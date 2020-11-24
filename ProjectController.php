<?php

namespace App\Http\Controllers;

use App\AppSetting;
use App\Project;
use App\ProjectCandidate;
use App\ProjectVote;
use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProjectController extends Controller
{

    /**
     * @return array
     */
    private function getValidatorRules()
    {
        return [
            'name' => 'required|max:100',
            'link' => 'nullable|url|max:100',
            'comment' => 'required|max:1000',
            'video_link_1' => 'nullable|url|max:100',
            'video_link_2' => 'nullable|url|max:100',
            'video_link_3' => 'nullable|url|max:100',
            'vote_until' => 'sometimes|required',
            'vote_2_until' => 'sometimes|required',
        ];
    }

    /**
     * Display a listing of the resource.
     *
     * @param Builder $builder
     * @param string $view Name of view.
     * @return \Illuminate\Http\Response
     */
    protected function index(Builder $builder, $view = 'project.index')
    {
        $models = $builder->with('created_by')->paginate(10);
        return view($view, compact('models'));
    }
    public function underReview()
    {
        return $this->index(Project::underReview());
    }

    public function rejected()
    {
        return $this->index(Project::whereStatus([
            Project::STATUS_REJECTED,
            Project::STATUS_CLOSED_AFTER_FIRST_VOTING,
            Project::STATUS_REJECTED_AFTER_SECOND_VOTING,
        ]));
    }

    public function waitForFirstVote()
    {
        return $this->index(Project::whereStatus(Project::STATUS_WAIT_FOR_FIRST_VOTING));
    }

    public function onFirstVoting()
    {
        return $this->index(Project::whereStatus(Project::STATUS_ON_FIRST_VOTING));
    }

    public function firstVotingFinished()
    {
        return $this->index(Project::whereStatus(Project::STATUS_FIRST_VOTING_COMPLETED));
    }

    public function onSecondVoting()
    {
        return $this->index(Project::whereStatus(Project::STATUS_ON_SECOND_VOTING));
    }

    public function secondVotingFinished()
    {
        return $this->index(Project::whereStatus(Project::STATUS_SECOND_VOTING_COMPLETED));
    }

    public function votings()
    {
        return $this->index(Project::whereStatus([Project::STATUS_ON_FIRST_VOTING, Project::STATUS_ON_SECOND_VOTING]), 'project.votings');
    }

    public function active(Request $request)
    {
        return $this->index(Project::whereStatus([Project::STATUS_FLOW_REGISTRATION, Project::STATUS_REGISTRATION_COMPLETED]), 'project.active');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('project.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), $this->getValidatorRules());

        $validator->sometimes('link', ['unique:projects,link'], function () {
            return AppSetting::findByName('user_can_send_same_project_on_review')->value === '0';
        });

        $validator->sometimes(['link', 'video_link_1'], ['required'], function () {
            return !\Auth::user()->isAdmin();
        });

        if ($validator->fails()) {
            return redirect(route('project.create'))
                ->withErrors($validator)
                ->withInput();
        }



        $data = $request->except('_token');
        $data['created_by_id'] = \Auth::id();

        $project = Project::create($data);
        \Session::flash('success', \Auth::user()->isSiteCrew() ? __('Project.message.project_moved_to_1_vote_queue') : __('Project.message.project_created'));
        return redirect('/');
    }

    public function show(Project $project)
    {
        $project->markAsRead();
        $first_voting_votes = new LengthAwarePaginator([], 0, 10);
        $second_voting_votes = new LengthAwarePaginator([], 0, 10);

        if (\Auth::user()->canSeeFirstVotingStatistic($project)) {
            $first_voting_votes = ProjectVote::byProject($project)->paginate(10, ['*'], 'first_voting_votes');
        }

        if (\Auth::user()->canSeeSecondVoteStatistic($project)) {
            $second_voting_votes = ProjectCandidate::byProject($project)->paginate(10, ['*'], 'second_voting_votes');
        }

        return view('project.show', compact('project', 'first_voting_votes', 'second_voting_votes'));
    }

    /**
     * Move user to page where he check and can vote for project.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function landing(Project $project)
    {
        $comments = ProjectVote::byProject($project)->accepted()->with('user')->paginate(10);

        if (Auth::check() && Auth::user()->can('firstVote', $project) && $project->voting_only) {
            // Client wanted separate logic for project with 1st voting only,
            // but he refused offer of developing separate model, as it's too expensive for him, therefore shitcode here.
            return view('project.vote_only_project', compact('project', 'comments'));
        }
        return view('project.landing', compact('project', 'comments'));
    }

    /**
     * Show the form for updating the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Project $project)
    {
        $request->session()->flashInput($project->toArray());
        return view('project.update', compact('project'));
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postUpdate(Request $request, Project $project)
    {
        $this->validate($request, $this->getValidatorRules());

        $project->update($request->all());
        \Session::flash('success', __('Project.message.project_updated'));
        return redirect(route('project.show', compact('project')));
    }

    /**
     * Accept a project under review.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function accept(Project $project)
    {
        $project->status = Project::STATUS_ACCEPTED;
        if ($project->save()) {
            \Session::flash('success', __('Project.message.project_accepted'));
        } else {
            \Session::flash('error', __('app.action_error'));
        }
        return back();
    }

    public function reject(Project $project)
    {
        return view('project.reject', compact('project'));
    }

    /**
     * Reject a project under review.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postReject(Project $project)
    {
        $project->status = Project::STATUS_REJECTED;
        if ($project->save()) {
            \Session::flash('success', __('Project.message.project_rejected'));
            return redirect(route('project.underReview'));
        } else {
            \Session::flash('error', __('app.action_error'));
            return back();
        }
    }

    /**
     * Move a project to queue for voting.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function moveToQueue(Project $project)
    {
        $project->status = Project::STATUS_WAIT_FOR_FIRST_VOTING;
        if ($project->save()) {
            \Session::flash('success', __('Project.message.project_moved_to_1_vote_queue'));
            return redirect(route('project.underReview'));
        } else {
            \Session::flash('error', __('app.action_error'));
            return back();
        }
    }

    /**
     * Show settings before start first voting.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function startFirstVoting(Project $project)
    {
        return view('project.start_first_voting', compact('project'));
    }

    /**
     * Start first voting.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postStartFirstVoting(Request $request, Project $project)
    {
        $validator = \Validator::make($request->all(), [
            'vote_until' => 'required',
            'first_voting_options' => sprintf('required|array|min:2|max:%d', Project::MAX_COUNT_VOTING_OPTIONS)
        ]);

        $validator->sometimes('voting_only', ['required'], function () use ($project) {
            return is_null($project->link) || is_null($project->video_link_1);
        });

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // todo check that date not in the past
        if ($project->startFirstVoting($request->get('vote_until'), $request->get('first_voting_options'), $request->has('voting_only'))) {
            \Session::flash('success', __('Project.message.first_voting_started'));
            return redirect(route('project.show', compact('project')));
        }
        \Session::flash('error', __('app.action_error'));
        return back();
    }

    /**
     * Accept a project under review.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function finishFirstVoting(Project $project)
    {
        if ($project->finishFirstVoting()) {
            \Session::flash('success', __('Project.message.first_voting_finished'));
        } else {
            \Session::flash('error', __('app.action_error'));
        }
        return back();
    }

    /**
     * Finish second voting.
     *
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function finishSecondVoting(Project $project)
    {
        if ($project->finishSecondVoting()) {
            \Session::flash('success', __('Project.message.second_voting_finished'));
        } else {
            \Session::flash('error', __('app.action_error'));
        }
        return back();
    }

    public function firstVotingDecision(Project $project)
    {
        return view('project.first_voting_desicion', compact('project'));
    }

    public function postFirstVotingDecision(Request $request, Project $project)
    {
        $this->validate($request, [
            'status' => sprintf('required|in:%d,%d', Project::STATUS_CLOSED_AFTER_FIRST_VOTING, Project::STATUS_ON_SECOND_VOTING),
            'first_voting_comment' => 'required|max:255',
            'participation_conditions' => 'max:255|required_if:status,' . Project::STATUS_ON_SECOND_VOTING,
            'vote_2_until' => sprintf('required_if:status,%d', Project::STATUS_ON_SECOND_VOTING),
        ], [
            'participation_conditions.required_if' => __('validation.required'),
            'vote_2_until.required_if' => __('validation.required'),
        ]);

        $project->first_voting_comment = $request->get('first_voting_comment');
        $project->participation_conditions = $request->get('participation_conditions');
        if ($request->get('status') == Project::STATUS_ON_SECOND_VOTING) {
            $saved = $project->startSecondVoting($request->get('vote_2_until'));
        } else {
            $project->status = $request->get('status');
            $saved= $project->save();
        }

        if ($saved) {
            if ($project->status == Project::STATUS_ON_SECOND_VOTING) {
                \Session::flash('success', __('Project.message.second_voting_started'));
            } else {
                \Session::flash('success', __('Project.message.project_closed'));
            }
            return redirect(route('project.show', compact('project')));
        }
        \Session::flash('error', __('app.action_error'));
        return back();
    }

    /**
     * @param Project $project
     * @return \Illuminate\Http\RedirectResponse
     */
    public function rejectAfterSecondVoting(Project $project)
    {
        $project->status = Project::STATUS_REJECTED_AFTER_SECOND_VOTING;
        if ($project->save()) {
            \Session::flash('success', __('Project.message.project_rejected'));
        } else {
            \Session::flash('error', __('app.action_error'));
        }
        return back();
    }
}
