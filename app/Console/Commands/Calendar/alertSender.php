<?php

namespace PandaLove\Console\Commands\Calendar;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Onyx\Calendar\Objects\Event as GameEvent;
use Onyx\Hangouts\Helpers\Messages;

class alertSender extends Command
{
    use DispatchesCommands;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alert:events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends alerts to games';

    /**
     * @var int
     */
    private $seconds_in_5minutes = 300;

    /**
     * @var int
     */
    private $seconds_in_15minutes = 900;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /** @var GameEvent[] $events */
        $events = GameEvent::where('alert_5', 0)
            ->orWhere('alert_15', 0)
            ->orderBy('start', 'ASC')
            ->get();

        $messenger = new Messages();

        if (count($events) > 0) {
            foreach ($events as $event) {
                $this->info('Checking event: '.$event->title);
                $diff = Carbon::now('America/Chicago')->diffInSeconds($event->start, false);
                $this->info('Event happens in '.$diff.' seconds.');

                // Uh oh, we missed this event. Skip it.
                if ($diff <= 0) {
                    $this->info('Event: '.$event->title.' has expired. Skipping');
                    $event->alert_5 = true;
                    $event->alert_15 = true;
                    $event->save();
                }

                if (!$event->alert_15) {
                    if ($diff <= $this->seconds_in_15minutes) {
                        $this->info('Alerting members of fireteam, that its 15 minutes before event');
                        foreach ($event->attendees as $attendee) {
                            $user = $attendee->user;
                            $messenger->sendMessage($user, '<strong>The Event: <i> ('.$event->getHumanType().') '.$event->title.'</i></strong> starts in about 15 minutes.<br /><br /><i>You RSVP`d to this Event, which is why you are being alerted</i>.');
                        }

                        $event->alert_15 = true;
                        $event->save();
                    }
                }

                if (!$event->alert_5) {
                    if ($diff <= $this->seconds_in_5minutes) {
                        $this->info('Alerting members of fireteam, that its 5 minutes before event.');
                        foreach ($event->attendees as $attendee) {
                            $user = $attendee->user;
                            $messenger->sendMessage($user, '<strong>The Event: <i> ('.$event->getHumanType().') '.$event->title.'</i></strong> starts in about 5 minutes.<br /><br /><i>You RSVP`d to this Event, which is why you are being alerted</i>.');
                        }

                        $event->alert_5 = true;
                        $event->save();
                    }
                }
            }
        }
    }
}
