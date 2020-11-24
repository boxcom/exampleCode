<?php
/**
 * After accept time for specific flow line was updated (as sponsor didn't accept his children on time)
 * or admin accepted his children manually, timing for rest of group must be updated and new notification must be sent to them.
 *
 */
namespace App\Jobs;

use App\FlowParticipant;
use App\ProjectCandidate;
use App\ProjectFlow;
use Carbon\Carbon;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class UpdateFlowTimingsAndNotify
{
    use Dispatchable, SerializesModels;
    /**
     * @var ProjectFlow
     */
    private $flow;

    /**
     * Create a new job instance.
     *
     * @param ProjectFlow $flow
     */
    public function __construct(ProjectFlow $flow)
    {
        //
        $this->flow = $flow;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $flow = $this->flow;
        if (!$flow->isOneChildTree()) {
            return; // Job only works for tree with one child.
        }

        $participant = $flow->getParticipantsQueryByLine()->first();

        if ($participant->registered) { // accept time was increased for sponsor
            while (true) {
                $registration_starts = $participant->getWhenAcceptationEnds();
                $sponsor = $participant;
                $participant = $participant->children()->first();

                if (!$participant) {
                    break; // Rest of the group updated
                }

                $this->updateParticipants($flow, $participant, $sponsor, $registration_starts);
            }
        } else {  // admin accepted on his own
            $sponsor = $participant->parent;
            $registration_starts = Carbon::now()->addMinutes(30); // Can register in 30 minutes

            while (true) {
                if (!$participant) {
                    break; // Rest of the group updated
                }

                $this->updateParticipants($flow, $participant, $sponsor, $registration_starts);

                $sponsor = $participant;
                $participant = $participant->children()->first();
                $registration_starts = $sponsor->getWhenAcceptationEnds();
            }

            // update timings for $participant and his children
        }
    }

    /**
     * Update data for participant and his sponsor.
     *
     * @param ProjectFlow $flow
     * @param ProjectCandidate $participant
     * @param ProjectCandidate $sponsor
     * @param Carbon $registration_starts
     */
    protected function updateParticipants(ProjectFlow $flow, $participant, $sponsor, Carbon $registration_starts)
    {
        // Update participant timing and notifications
        $participant->must_be_registered_from = $registration_starts;
        $participant->accept_stage_starts_at = ProjectFlow::addRegisterTime($flow, $registration_starts, $participant->level);
        $participant->notification_sent = null;
        $participant->notify_about_accept_sent = null;
        $participant->save();

        // Update sponsor
        $sponsor->must_accept_children_from = $participant->accept_stage_starts_at;
        $sponsor->save();
    }
}
