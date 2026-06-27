<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SegmentEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $segment;
    public $action;

    public function __construct($segment, $action)
    {
        $this->segment = $segment;
        $this->action = $action;
    }

    public function broadcastOn()
    {
        return new Channel('segments');
    }

    public function broadcastAs()
    {
        return 'segment.event';
    }

    public function broadcastWith()
    {
        return [
            'segment' => [
                'segment_id'    => $this->segment->segment_id ?? null,
                'attendance_id' => $this->segment->attendance_id ?? null,
                'type'          => $this->segment->type ?? null,
                'segment_type'  => $this->segment->segment_type ?? null,
                'site_id'       => $this->segment->site_id ?? null,
                'site_name'     => $this->segment->site_name ?? null,
                'start_time'    => $this->segment->start_time ?? null,
                'end_time'      => $this->segment->end_time ?? null,
                'action'        => $this->action,
            ]
        ];
    }
}
