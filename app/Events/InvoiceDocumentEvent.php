<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceDocumentEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $document;

    public $action;

    public function __construct($document, $action)
    {
        $this->document = $document;
        $this->action = $action;
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('invoice-documents'),
        ];
    }

    public function broadcastAs()
    {
        return 'invoice-documents.event';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'document' => [
                'document_id' => $this->document['document_id'] ?? null,
                'status' => $this->document['status'] ?? null,
                'vendor_name_raw' => $this->document['vendor_name_raw'] ?? null,
                'billing_month' => $this->document['billing_month'] ?? null,
                'total_with_tax' => $this->document['total_with_tax'] ?? null,
            ],
        ];
    }
}
