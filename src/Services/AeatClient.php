<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Support\Facades\Log;

class AeatClient
{
    private string $baseUri;
    private string $certPath;
    private ?string $certPassword;
    private Client $client;
    private bool $production;

    public function __construct(string $certPath, ?string $certPassword = null, bool $production = false)
    {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
        $this->baseUri = $production
            ? 'https://www1.aeat.es'
            : 'https://prewww1.aeat.es';
        $this->client = new Client([
            'cert' => ($certPassword === null) ? $certPath : [$certPath, $certPassword],
            'base_uri' => $this->baseUri,
            'headers' => [
                'User-Agent' => 'LaravelVerifactu/1.0',
            ],
        ]);
    }

    /**
     * Format a number to 2 decimal places
     *
     * @param mixed $value
     * @return string
     */
    private function fmt2($value): string
    {
        return sprintf('%.2f', (float) $value);
    }

    /**
     * Build fingerprint/hash for invoice chaining
     *
     * @param string $issuerVat
     * @param string $numSerie
     * @param string $fechaExp
     * @param string $tipoFactura
     * @param string $cuotaTotal
     * @param string $importeTotal
     * @param string $ts
     * @param string $prevHash
     * @return string
     */
    private function buildFingerprint(
        string $issuerVat,
        string $numSerie,
        string $fechaExp,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        string $ts,
        string $prevHash = ''
    ): string {
        $raw = 'IDEmisorFactura=' . $issuerVat
            . '&NumSerieFactura=' . $numSerie
            . '&FechaExpedicionFactura=' . $fechaExp
            . '&TipoFactura=' . $tipoFactura
            . '&CuotaTotal=' . $cuotaTotal
            . '&ImporteTotal=' . $importeTotal
            . '&Huella=' . $prevHash
            . '&FechaHoraHusoGenRegistro=' . $ts;
        return strtoupper(hash('sha256', $raw));
    }

    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param Invoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param \Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    public function sendInvoice(\Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice $invoice, ?array $previous = null): array
    {
        // 1. Obtener datos del emisor desde config
        $issuer = config('verifactu.issuer');
        $issuerName = $issuer['name'] ?? '';
        $issuerVat = $issuer['vat'] ?? '';

        // 2. Mapear Invoice a estructura AEAT
        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
        ];

        // 3. Mapear desgloses (Breakdown) con campos requeridos
        $breakdowns = $invoice->getBreakdowns();

        $detalle = [];
        foreach ($breakdowns as $breakdown) {
            $detalle[] = [
                'ClaveRegimen' => $breakdown->getRegimeType(),
                'CalificacionOperacion' => $breakdown->getOperationType(),
                'TipoImpositivo' => (float) $breakdown->getTaxRate(),
                'BaseImponibleOimporteNoSujeto' => $this->fmt2($breakdown->getBaseAmount()),
                'CuotaRepercutida' => $this->fmt2($breakdown->getTaxAmount()),
            ];
        }

        // Si no hay desgloses, crear uno por defecto
        if (count($detalle) === 0) {
            $base = $this->fmt2($invoice->getTotalAmount() - $invoice->getTaxAmount());
            $detalle[] = [
                'ClaveRegimen' => '01',
                'CalificacionOperacion' => 'S1',
                'TipoImpositivo' => 0.0,
                'BaseImponibleOimporteNoSujeto' => $base,
                'CuotaRepercutida' => $this->fmt2(0),
            ];
        }

        // 4. Generar timestamp y preparar datos para huella
        $ts = \Carbon\Carbon::now('UTC')->format('c');
        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $fechaExpYMD = $invoice->getIssueDate()->format('Y-m-d');
        $tipoFactura = $invoice->getInvoiceType();
        $cuotaTotal = $this->fmt2($invoice->getTaxAmount());
        $importeTotal = $this->fmt2($invoice->getTotalAmount());
        $prevHash = $previous['hash'] ?? $invoice->getPreviousHash() ?? '';

        // 5. Generar huella (hash)
        $huella = $this->buildFingerprint(
            $issuerVat,
            $numSerie,
            $fechaExp,
            $tipoFactura,
            $cuotaTotal,
            $importeTotal,
            $ts,
            $prevHash
        );

        // 6. Construir Encadenamiento
        $encadenamiento = $previous
            ? [
                'RegistroAnterior' => [
                    'IDEmisorFactura' => $issuerVat,
                    'NumSerieFactura' => $previous['number'],
                    'FechaExpedicionFactura' => $previous['date'],
                    'Huella' => $previous['hash'],
                ],
            ]
            : ['PrimerRegistro' => 'S'];

        // 7. Construir RegistroAlta
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $numSerie,
                'FechaExpedicionFactura' => $fechaExp,
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $tipoFactura,
            'DescripcionOperacion' => $invoice->getOperationDescription(),
            'Desglose' => ['DetalleDesglose' => $detalle],
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Encadenamiento' => $encadenamiento,
            'SistemaInformatico' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
                'NombreSistemaInformatico' => env('APP_NAME', 'LaravelVerifactu'),
                'IdSistemaInformatico' => '01',
                'Version' => '1.0',
                'NumeroInstalacion' => '001',
                'TipoUsoPosibleSoloVerifactu' => 'S',
                'TipoUsoPosibleMultiOT' => 'N',
                'IndicadorMultiplesOT' => 'N',
            ],
            'FechaHoraHusoGenRegistro' => $ts,
            'TipoHuella' => '01',
            'Huella' => $huella,
        ];

        // 8. Mapear destinatarios (opcional, solo si existen)
        $recipients = $invoice->getRecipients();
        if ($recipients->count() > 0) {
            $destinatarios = [];
            foreach ($recipients as $recipient) {
                $r = ['NombreRazon' => $recipient->getName()];
                $taxId = $recipient->getTaxId();
                if (!empty($taxId)) {
                    $r['NIF'] = $taxId;
                }
                $destinatarios[] = $r;
            }
            $registroAlta['Destinatarios'] = ['IDDestinatario' => $destinatarios];
        }

        $body = [
            'Cabecera' => $cabecera,
            'RegistroFactura' => [
                ['RegistroAlta' => $registroAlta]
            ],
        ];

        // 9. Configurar SoapClient y enviar
        $wsdl = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP?wsdl'
            : 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';
        $location = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
        $options = [
            'local_cert' => $this->certPath,
            'passphrase' => $this->certPassword,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => 0,
            'soap_version' => SOAP_1_1,
            'connection_timeout' => 30,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
                'http' => [
                    'user_agent' => 'LaravelVerifactu/1.0',
                ],
            ]),
        ];

        try {
            $client = new \SoapClient($wsdl, $options);
            $client->__setLocation($location);
            $response = $client->__soapCall('RegFactuSistemaFacturacion', [$body]);
            return [
                'status' => 'success',
                'request' => $client->__getLastRequest(),
                'response' => $client->__getLastResponse(),
                'aeat_response' => $response,
                'hash' => $huella,
                'number' => $numSerie,
                'date' => $fechaExp,
                'timestamp' => $ts,
                'first' => $previous ? false : true,
            ];
        } catch (\SoapFault $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'request' => isset($client) ? $client->__getLastRequest() : null,
                'response' => isset($client) ? $client->__getLastResponse() : null,
            ];
        }
    }

    // Métodos adicionales para anulación, consulta, etc. pueden añadirse aquí
}
