<?php

namespace Squareetlabs\VeriFactu\Console\Commands;

use Illuminate\Console\Command;

class MakeAdapterCommand extends Command
{
    protected $signature = 'verifactu:make-adapter {model : The model class name}';

    protected $description = 'Generate VeriFactu contract implementation for an existing model';

    public function handle()
    {
        $modelName = $this->argument('model');
        $modelClass = "App\\Models\\{$modelName}";

        // We don't strictly check if class exists because user might be creating it
        // or it might be in a different namespace, but we assume App\Models for simplicity
        // in this helper command.

        $stub = $this->getStub();

        $this->info("Add the following methods to your {$modelName} model:");
        $this->line('');
        $this->line($stub);
        $this->line('');
        $this->info("Don't forget to add imports:");
        $this->info("use Squareetlabs\\VeriFactu\\Contracts\\VeriFactuInvoice;");
        $this->info("use Squareetlabs\\VeriFactu\\Contracts\\VeriFactuBreakdown;");
        $this->info("use Squareetlabs\\VeriFactu\\Contracts\\VeriFactuRecipient;");
        $this->info("use Illuminate\\Support\\Collection;");
        $this->info("use Carbon\\Carbon;");
        $this->line('');
        $this->info("And implement the interface: class {$modelName} extends Model implements VeriFactuInvoice");

        return 0;
    }

    protected function getStub(): string
    {
        return <<<'PHP'
    // VeriFactuInvoice Contract Implementation
    
    public function getInvoiceNumber(): string
    {
        return $this->number; // Adjust to your field name
    }

    public function getIssueDate(): Carbon
    {
        return $this->date; // Adjust to your field name
    }

    public function getInvoiceType(): string
    {
        return $this->type->value ?? (string)$this->type; // Adjust if needed
    }

    public function getTotalAmount(): float
    {
        return (float)$this->total; // Adjust to your field name
    }

    public function getTaxAmount(): float
    {
        return (float)$this->tax; // Adjust to your field name
    }

    public function getCustomerName(): string
    {
        return $this->customer_name; // Adjust to your field name
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customer_tax_id; // Adjust to your field name
    }

    public function getBreakdowns(): Collection
    {
        // If you have a single tax rate, create a breakdown on the fly:
        return collect([
            new class($this) implements VeriFactuBreakdown {
                public function __construct(private $invoice) {}
                public function getRegimeType(): string { return '01'; }
                public function getOperationType(): string { return 'S1'; }
                public function getTaxRate(): float { return 0.0; } // Adjust
                public function getBaseAmount(): float { return $this->invoice->getTotalAmount(); }
                public function getTaxAmount(): float { return $this->invoice->getTaxAmount(); }
            }
        ]);
        
        // Or if you have a relationship:
        // return $this->breakdowns;
    }

    public function getRecipients(): Collection
    {
        // Return empty collection if you don't have multiple recipients
        return collect();
    }

    public function getPreviousHash(): ?string
    {
        return $this->previous_hash; // Adjust to your field name
    }

    public function getOperationDescription(): string
    {
        return $this->description ?? 'Invoice issued'; // Adjust
    }
PHP;
    }
}
