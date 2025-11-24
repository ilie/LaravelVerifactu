<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Squareetlabs\VeriFactu\Enums\TaxType;
use Squareetlabs\VeriFactu\Enums\RegimeType;
use Squareetlabs\VeriFactu\Enums\OperationType;
use Illuminate\Support\Facades\Config;

class AeatClientRefactorTest extends TestCase
{
    public function testSendInvoiceGeneratesCorrectStructure(): void
    {
        // Configurar datos del emisor
        Config::set('verifactu.issuer', [
            'name' => 'Issuer Test',
            'vat' => 'B12345678'
        ]);

        // Mockear Breakdown
        $breakdownMock = $this->createMock(\Squareetlabs\VeriFactu\Contracts\VeriFactuBreakdown::class);
        $breakdownMock->method('getRegimeType')->willReturn(RegimeType::GENERAL->value);
        $breakdownMock->method('getOperationType')->willReturn(OperationType::SUBJECT_NO_EXEMPT_NO_REVERSE->value);
        $breakdownMock->method('getTaxRate')->willReturn(21.0);
        $breakdownMock->method('getBaseAmount')->willReturn(100.0);
        $breakdownMock->method('getTaxAmount')->willReturn(21.0);

        // Mockear Recipient
        $recipientMock = $this->createMock(\Squareetlabs\VeriFactu\Contracts\VeriFactuRecipient::class);
        $recipientMock->method('getName')->willReturn('Test Customer');
        $recipientMock->method('getTaxId')->willReturn('12345678A');

        // Mockear Invoice
        $invoiceMock = $this->createMock(\Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice::class);
        $invoiceMock->method('getInvoiceNumber')->willReturn('TST-001');
        $invoiceMock->method('getIssueDate')->willReturn(now());
        $invoiceMock->method('getInvoiceType')->willReturn(InvoiceType::STANDARD->value);
        $invoiceMock->method('getTotalAmount')->willReturn(121.0);
        $invoiceMock->method('getTaxAmount')->willReturn(21.0);
        $invoiceMock->method('getBreakdowns')->willReturn(collect([$breakdownMock]));
        $invoiceMock->method('getRecipients')->willReturn(collect([$recipientMock]));
        $invoiceMock->method('getPreviousHash')->willReturn(null);
        $invoiceMock->method('getOperationDescription')->willReturn('Test Operation');
        $invoiceMock->method('getOperationDate')->willReturn(now()->subDay());
        $invoiceMock->method('getTaxPeriod')->willReturn('01');
        $invoiceMock->method('getCorrectionType')->willReturn(null);
        $invoiceMock->method('getExternalReference')->willReturn('REF-123');

        // Mockear SoapClient
        $soapClientMock = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__setLocation', '__soapCall', '__getLastRequest', '__getLastResponse'])
            ->getMock();

        $soapClientMock->expects($this->once())
            ->method('__soapCall')
            ->with(
                'RegFactuSistemaFacturacion',
                $this->callback(function ($args) {
                    $body = $args[0];
                    $registroAlta = $body['RegistroFactura'][0]['RegistroAlta'];

                    // Verificar estructura básica y nuevos campos
                    return isset($body['Cabecera']) &&
                        isset($registroAlta) &&
                        $body['Cabecera']['ObligadoEmision']['NIF'] === 'B12345678' &&
                        $registroAlta['IDFactura']['NumSerieFactura'] === 'TST-001' &&
                        isset($registroAlta['FechaOperacion']) &&
                        isset($registroAlta['PeriodoImpositivo']) &&
                        $registroAlta['PeriodoImpositivo']['Periodo'] === '01' &&
                        $registroAlta['RefExterna'] === 'REF-123';
                })
            )
            ->willReturn(new \stdClass());

        // Instanciar cliente usando una clase anónima para sobrescribir getSoapClient
        $certPath = '/path/to/cert.pem';
        $certPassword = 'password';

        $client = new class ($certPath, $certPassword, false, $soapClientMock) extends AeatClient {
            private $soapClientMock;

            public function __construct($certPath, $certPassword, $production, $soapClientMock)
            {
                parent::__construct($certPath, $certPassword, $production);
                $this->soapClientMock = $soapClientMock;
            }

            protected function getSoapClient(): \SoapClient
            {
                return $this->soapClientMock;
            }
        };

        $result = $client->sendInvoice($invoiceMock);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('TST-001', $result['number']);
    }
}
