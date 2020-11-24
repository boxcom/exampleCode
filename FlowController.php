<?php

namespace App\Http\Controllers;

use App\FlowParticipant;
use App\Jobs\UpdateFlowTimingsAndNotify;
use App\Project;
use App\ProjectFlow;
use App\User;
use DB;
use Illuminate\Http\Request;
use Session;

class FlowController extends Controller
{

    /**
     * Show the form for creating a new resource.
     *
     * @param Project $project
     * @return \Illuminate\Http\Response
     */
    public function create(Project $project)
    {
        $candidates = $project->candidates()->get();
        return view('flow.create', compact('project', 'candidates'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, Project $project)
    {
        $this->validate($request, [
            'registration_type' => sprintf('required|in:%s,%s,%s', ProjectFlow::TYPE_ONE_CHILD_TREE,
                ProjectFlow::TYPE_TWO_CHILDREN_TREE, ProjectFlow::TYPE_THREE_CHILDREN_TREE),
            'leader_id' => 'required:exists:users',
            'how_much_users_in_one_group' => 'required_if:registration_type,' . ProjectFlow::TYPE_ONE_CHILD_TREE,
            'must_be_registered_from' => 'required',
            'time_for_registration' => 'required',
            'time_for_accept' => 'required',
            'comments' => 'required|max:255',
            'auto_continue' => 'required|boolean',
        ]);

        $flow = ProjectFlow::createAndStart($project, $request->all());

        if ($flow) {
            return redirect(route('flow.show', compact('flow')));
        }

        Session::flash('error', __('app.action_error'));
        return back()->withInput();


    }

    /**
     * Display the specified resource.
     *
     * @param  \App\ProjectFlow  $flow
     * @return \Illuminate\Http\Response
     */
    public function show(ProjectFlow $flow)
    {
        $participant = $flow->isUserParticipant(\Auth::user());
        if ($participant) {
            \request()->session()->flashInput($participant->toArray());
        }
        return view('flow.show', compact('flow', 'participant'));
    }

    public function getParticipants(Request $request, ProjectFlow $flow)
    {
        $id = $request->get('id') === '#' ? null : $request->get('id');
        return view('flow.participants', ['participants' => $flow->getParticipantsByParentId($id)]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param ProjectFlow $flow
     * @return \Illuminate\Http\Response
     */
    public function registerParticipant(Request $request, ProjectFlow $flow)
    {
        $this->validate($request, [
            'referral_url' => 'required|max:255|url',
            'referral_name' => 'required|max:255',
            'referral_login' => 'required|max:255',
        ]);

        $participant = $flow->isUserParticipant(\Auth::user());
        if ($participant && $participant->saveParticipantRegistrationData($request->all())) {
            if ($participant->needToAcceptChildrenInFuture()) {
                Session::flash('success', __('FlowParticipant.messages.wait_further_notification'));
            } else {
                Session::flash('success', __('app.data_saved'));
            }
            return redirect()->back();
        }

        Session::flash('error', __('app.action_error'));
        return back()->withInput();
    }

    public function acceptParticipant(ProjectFlow $flow, FlowParticipant $participant)
    {
        if ($flow->isUserParticipant($participant->user) && $participant->acceptRegistration()) {
            $flow->moveToNextLevelIfPossible();
            Session::flash('success', __('app.data_saved'));
            return redirect()->back();
        }

        Session::flash('error', __('app.action_error'));
        return back();
    }

    /**
     * Show form for continue registration process.
     *
     * @param Request $request
     * @param ProjectFlow $flow
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getContinue(Request $request, ProjectFlow $flow)
    {
        $project = $flow->project;
        $candidates = $project->candidates()->get();
        if (!$request->session()->has('errors')) {
            $request->session()->flashInput($flow->toArray());
        }
        return view('flow.update', compact('project', 'flow', 'candidates'));
    }

    /**
     * Continue registration process.
     *
     * @param Request $request
     * @param ProjectFlow $flow
     * @return $this|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postContinue(Request $request, ProjectFlow $flow)
    {
        $this->validate($request, [
            'how_much_users_in_one_group' => $flow->isOneChildTree() ? 'required' : 'nullable',
            'must_be_registered_from' => 'required',
            'time_for_registration' => 'required',
            'time_for_accept' => 'required',
            'comments' => 'required|max:255',
            'auto_continue' => 'required|boolean',
        ]);

        $ok = $flow->continueRegistration($request->all());

        if ($ok) {
            return redirect(route('flow.show', compact('flow')));
        }

        Session::flash('error', __('app.action_error'));
        return back()->withInput();
    }

    /**
     * Show form for continue accept stage process.
     *
     * @param Request $request
     * @param ProjectFlow $flow
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getContinueAccept(Request $request, ProjectFlow $flow)
    {
        if ($flow->firstLine()) { // Admin can just accept participants
            return redirect(route('flow.acceptParticipants', compact('flow')));
        }

        if (!$request->session()->has('errors')) {
            $request->session()->flashInput($flow->toArray());
        }
        return view('flow.continueAccept', compact('flow'));
    }


    /**
     * @param Request $request
     * @param ProjectFlow $flow
     * @return $this|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postContinueAccept(Request $request, ProjectFlow $flow)
    {
        $this->validate($request, ['time_for_accept' => 'required']);
        if ($flow->updateAcceptTime($request->get('time_for_accept'))) {
            $flow->notifyParticipantsThatAcceptTimeWasUpdated();
            dispatch(new UpdateFlowTimingsAndNotify($flow));
            return redirect(route('flow.show', compact('flow')));
        }

        Session::flash('error', __('app.action_error'));
        return back()->withInput();
    }

    /**
     * Show form for continue accept stage process.
     *
     * @param Request $request
     * @param ProjectFlow $flow
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getAcceptParticipants(Request $request, ProjectFlow $flow)
    {
        if (!\Auth::check() || !\Auth::user()->isAdmin()) {
            abort(403); // Only admin can see this page.
        }
        if (!$flow->hasNonAcceptedParticipants()) { // All accepted. Redirected from FlowController@acceptParticipant
            dispatch(new UpdateFlowTimingsAndNotify($flow));
            return redirect(route('flow.show', compact('flow')));
        }

        if (!$request->session()->has('errors')) {
            $request->session()->flashInput($flow->toArray());
        }
        return view('flow.acceptParticipants', compact('flow'));
    }

    public function getRemoveParticipant(ProjectFlow $flow, FlowParticipant $participant)
    {
        if ($flow->isOneChildTree() && $flow->removeRestOfTheGroup()) {
            return redirect(route('flow.continue', compact('flow')));
        }
        $candidates = $flow->getNotRegisteredCandidatesQuery()->get();
        return view('flow.removeParticipant', compact('flow', 'participant', 'candidates'));
    }

    public function postRemoveParticipant(Request $request, ProjectFlow $flow, FlowParticipant $participant)
    {
        $this->validate($request, [
            'user_id' => 'required:exists:users',
            'must_be_registered_from' => 'required',
            'time_for_registration' => 'required|not_in:"0:00"',
            'time_for_accept' => 'required|not_in:"0:00"',
            'comments' => 'required|max:255',
            'deleted_reason' => 'required|max:255',
        ]);

        $user = User::findOrFail($request->get('user_id'));

        try {
            DB::beginTransaction();
            if ($flow->removeParticipant($participant, $request->get('deleted_reason'))
                && $participant->updateTimings($request->get('time_for_registration'), $request->get('time_for_accept'))
                && $participant->createInheritor($user, $request->get('must_be_registered_from'))
                && $flow->update(['on_pause' => false])) {
                DB::commit();
                Session::flash('success', __('app.data_saved'));
                return redirect(route('flow.show', compact('flow')));

            } else {
                DB::rollBack();
                Session::flash('error', __('app.action_error'));
                return back()->withInput();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage(), $e->getTrace());
            Session::flash('error', __('app.action_error'));
            return back()->withInput();
        }

    }

}
