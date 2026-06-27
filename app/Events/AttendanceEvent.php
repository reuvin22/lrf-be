<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public $attendance;
    public $action;
    public function __construct($attendance, $action)
    {
        $this->attendance = $attendance;
        $this->action = $action;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('attendances'),
        ];
    }

    public function broadcastAs()
    {
        return 'attendances.event';
    }

    public function broadcastWith(): array
    {
        return [
            'action'     => $this->action,
            'attendance' => [
                'employee_id'        => $this->attendance->employee_id        ?? null,
                'work_date'          => $this->attendance->work_date          ?? null,
                'status'             => $this->attendance->status             ?? null,
                'total_work_minutes' => $this->attendance->total_work_minutes ?? null,
                'overtime_minutes'   => $this->attendance->overtime_minutes   ?? null,
            ],
        ];
    }
}
