<?php
/**
 * Participations can be in 7 possible stages:
 *
 * confirmed	cancelled	no-show	reschedule	completed	description
 * 0			0			0		0			0			unconfirmed (called but no response)
 * 1			0			0		0			0			confirmed, not yet completed
 * 1			0			0		0			1			confirmed, completed
 * 1			0			0		1			0			confirmed, to be rescheduled
 * 1			0			1		0			0			confirmed, but no-show
 * 1			1			0		0			0			confirmed, cancelled later
 * 0			1			0		0			0			cancelled upfront
 *
 * @author Martijn van der Klis
 *
 */
class Participation extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->authenticate->redirect_except();
		reset_language(current_language());

		$this->form_validation->set_error_delimiters('<p class="error">', '</p>');
	}

	/////////////////////////
	// CRUD-actions
	/////////////////////////

	/** Specifies the contents of the default page. */
	public function index()
	{
		create_participation_table();
		switch (current_role())
		{
			case UserRole::Admin: $source = 'participation/table/'; break;
			case UserRole::Leader: 	$source = 'participation/table_by_leader/'; break;
			default: $source = 'participation/table_by_caller/'; break;
		}
		$data['ajax_source'] = $source;
		$data['page_title'] = lang('participations');

		$this->load->view('templates/header', $data);
		$this->load->view('templates/list_view', $data);
		$this->load->view('templates/footer');
	}

	/** Shows the page for a single participation */
	public function get($participation_id)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$data['participation'] = $participation;
		$data['participant'] = $participant;
		$data['experiment'] = $experiment;
		$data['page_title'] = lang('participation');

		$this->load->view('templates/header', $data);
		$this->load->view('participation_view', $data);
		$this->load->view('templates/footer');
	}

	/** Deletes a participation */
	public function delete($participation_id)
	{
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$this->participationModel->delete_participation($participation_id);

		flashdata(sprintf(lang('part_deleted'), name($participant), $experiment->name));
		redirect('participation', 'refresh');
	}

	/////////////////////////
	// Other views
	/////////////////////////

	/** Shows the page for a specific experiment */
	public function experiment($experiment_id)
	{
		$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);
		$data['page_title'] = sprintf(lang('participations_for'), $experiment->name);

		if (is_risk($experiment))
		{
			$data['experiment_id'] = $experiment_id;

			$this->load->view('templates/header', $data);
			$this->load->view('participation_risk_view', $data);
			$this->load->view('templates/footer');
		}
		else
		{
			create_participation_table();
			$data['ajax_source'] = 'participation/table/0/' . $experiment_id;

			$this->load->view('templates/header', $data);
			$this->load->view('templates/list_view', $data);
			$this->load->view('templates/footer');
		}
	}

	/**
	 *
	 * Shows the page for calling a participant
	 * @param integer $participant_id
	 * @param integer $experiment_id
	 * @param integer $weeks_ahead
	 */
	public function call($participant_id, $experiment_id, $weeks_ahead = WEEKS_AHEAD)
	{
		// Retrieve entities
		$participant = $this->participantModel->get_participant_by_id($participant_id);
		$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);

		// Check current user is caller for experiment
		if (!$this->callerModel->is_caller_for_experiment(current_user_id(), $experiment_id))
		{
			$data['error'] = sprintf(lang('not_caller'), $experiment->name);
			$this->load->view('templates/error', $data);
			return;
		}
		// Check current participant is callable for experiment
		if (!in_array($participant, $this->participantModel->find_participants($experiment, $weeks_ahead)))
		{
			$data['error'] = sprintf(lang('not_callable_for'), name($participant), $experiment->name);
			$this->load->view('templates/error', $data);
			return;
		}
		// Check current participant is not being called
		if ($this->participationModel->is_locked_participant($participant_id, $experiment_id))
		{
			$data['error'] = sprintf(lang('in_conversation'), name($participant));
			$this->load->view('templates/error', $data);
			return;
		}

		// Retrieve links
		$comments = $this->commentModel->get_comments_by_participant($participant_id);
		$impediments = $this->impedimentModel->get_impediments_by_participant($participant_id);
		$experiments = $this->experimentModel->get_experiments_by_participant($participant_id);
		$participations = $this->participationModel->get_participations_by_experiment($experiment_id);
		$participations = $this->participationModel->get_participations_by_experiment($experiment_id);

		// Retrieve or create participation record
		$participation = $this->participationModel->get_participation($experiment_id, $participant_id);
		if (!empty($participation))
		{
			$participation_id = $participation->id;
		}
		else
		{
			$participation_id = $this->participationModel->create_participation($experiment, $participant);
			$participation = $this->participationModel->get_participation_by_id($participation_id);
		}

		// Find the previous call (if is exists)
		$previous_call = $this->callModel->last_call($participation_id);

		// Lock the participation record
		$this->participationModel->lock($participation_id);

		// Create call record
		$call_id = $this->callModel->create_call($participation_id);

		// Check whether the participant is multilingual and has languages defined
		$verify_languages = FALSE;
		if ($participant->multilingual)
		{
			$languages = $this->languageModel->get_languages_by_participant($participant_id);
			$verify_languages = count($languages) <= 1; // Less than one language => not multilingual...
		}

		// Create page data
		$data = get_min_max_days($participant, $experiment);
		$data['participant'] = $participant;
		$data['experiment'] = $experiment;
		$data['participation'] = $participation;
		$data['participation_id'] = $participation_id;
		$data['call_id'] = $call_id;
		$data['previous_call'] = $previous_call;
		$data['comment_size'] = count($comments);
		$data['impediment_table'] = create_impediment_table($impediments);
		$data['impediment_size'] = count($impediments);
		$data['last_experiment'] = $this->participantModel->last_experiment($participant_id);
		$data['last_called'] = $this->participantModel->last_called($participant_id);
		$data['nr_participations'] = count($participations);
		$data['verify_languages'] = $verify_languages;
		$data['page_title'] = sprintf(lang('call_participant'), name($participant));

		$this->load->view('templates/header', $data);
		$this->load->view('participation_call', $data);
		$this->load->view('templates/footer');
	}

	/** Shows an overview of the no-shows per participant */
	public function no_shows($experiment_id = NULL)
	{
		if (isset($experiment_id))
		{
			$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);
			$participations = $this->participationModel->count_participations('noshow', $experiment_id);
			$data['page_title'] = sprintf(lang('no_shows_for'), $experiment->name);
		}
		else
		{
			$participations = $this->participationModel->count_participations('noshow');
			$data['page_title'] = sprintf(lang('no_shows'));
		}

		$data['table'] = create_participation_counter_table($participations, lang('no_shows'));
		$data['sort_column'] = 1;	// sort on count of no shows
		$data['sort_order'] = 'desc';

		$this->load->view('templates/header', $data);
		$this->load->view('templates/table_view', $data);
		$this->load->view('templates/footer');
	}

	/** Shows an overview of the interrupted participations per participant */
	public function interruptions($experiment_id = NULL)
	{
		if (isset($experiment_id))
		{
			$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);
			$participations = $this->participationModel->count_participations('interrupted', $experiment_id);
			$data['page_title'] = sprintf(lang('interruptions_for'), $experiment->name);
		}
		else
		{
			$participations = $this->participationModel->count_participations('interrupted');
			$data['page_title'] = sprintf(lang('interruptions'));
		}

		$data['table'] = create_participation_counter_table($participations, lang('interruptions'));
		$data['sort_column'] = 1;	// sort on count of interruptions
		$data['sort_order'] = 'desc';

		$this->load->view('templates/header', $data);
		$this->load->view('templates/table_view', $data);
		$this->load->view('templates/footer');
	}

	/////////////////////////
	// Other actions
	/////////////////////////

	/** Reschedules a participation */
	public function reschedule($participation_id)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$data = get_min_max_days($participant, $experiment);
		$data['appointment'] = output_datetime($participation->appointment, TRUE);
		$data['participation'] = $participation;
		$data['participant'] = $participant;
		$data['experiment'] = $experiment;

		$this->load->view('templates/header', $data);
		$this->load->view('participation_reschedule', $data);
		$this->load->view('templates/footer');
	}

	/** Submits the rescheduling of a participation */
	public function reschedule_submit($participation_id)
	{
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$this->form_validation->set_rules('appointment', lang('appointment'), 'trim|required');

		// Run validation
		if (!$this->form_validation->run())
		{
			// If not succeeded, return to previous page
			redirect('/participation/reschedule/' . $participation_id, 'refresh');
		}
		else
		{
			// If succeeded, insert data into database
			$appointment = input_datetime($this->input->post('appointment'));
			$this->participationModel->reschedule($participation_id, $appointment);

			flashdata(sprintf(lang('part_rescheduled'), name($participant), $experiment->name));

			redirect('/participation/experiment/' . $experiment->id, 'refresh');
		}
	}

	/** Cancels a participation */
	public function cancel($participation_id)
	{
		$this->participationModel->cancel($participation_id, FALSE);

		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
		flashdata(sprintf(lang('part_cancelled'), name($participant), $experiment->name));

		redirect($this->agent->referrer(), 'refresh');
	}

	/** No-shows a participation */
	public function no_show($participation_id)
	{
		$this->participationModel->no_show($participation_id);

		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
		flashdata(sprintf(lang('part_no_show'), name($participant), $experiment->name));

		redirect($this->agent->referrer(), 'refresh');
	}

	/** Completes a participation */
	public function completed($participation_id, $pp_comment = '')
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$data['participation_id'] = $participation_id;
		$data['participant_name'] = name($participant);
		$data['participant'] = $participant;
		$data['experiment_name'] = $experiment->name;
		$data['experiment_id'] = $experiment->id;
		$data = add_fields($data, 'participation', $participation);

		// Interrupted and pp_comment are a bit silly...
		$data['interrupted'] = '';
		$data['pp_comment'] = $pp_comment;

		$this->load->view('templates/header', $data);
		$this->load->view('participation_complete', $data);
		$this->load->view('templates/footer');
	}

	/** Submits the rescheduling of a participation */
	public function completed_submit($participation_id)
	{
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$this->form_validation->set_rules('part_number', lang('part_number'), 'trim|required');
		$this->form_validation->set_rules('interrupted', lang('interrupted'), 'required');
		$this->form_validation->set_rules('comment', lang('comment'), 'trim|required');

		// Run validation
		if (!$this->form_validation->run())
		{
			// If not succeeded, return to previous page
			$pp_comment = $this->input->post('pp_comment');
			$this->completed($participation_id, $pp_comment);
		}
		else
		{
			// If succeeded, insert data into database
			$participation = array(
				'part_number' 	=> $this->input->post('part_number'),
				'interrupted' 	=> $this->input->post('interrupted'),
				'comment'  		=> $this->input->post('comment')
			);
			$this->participationModel->completed($participation_id, $participation);
				
			// Add (possible) comment
			$comment = $this->post_comment($participant->id);
			if (!empty($comment)) $this->commentModel->add_comment($comment);

			flashdata(sprintf(lang('part_completed'), name($participant), $experiment->name));
			redirect('/participation/experiment/' . $experiment->id, 'refresh');
		}
	}

	/** Posts the data for a comment */
	private function post_comment($participant_id)
	{
		$comment = $this->input->post('pp_comment');
		if (empty($comment)) return NULL;

		return array(
				'body'				=> $comment,
				'participant_id' 	=> $participant_id,
				'user_id'		 	=> current_user_id()
		);
	}

	/////////////////////////
	// Table
	/////////////////////////

	public function table($participant_id = NULL, $experiment_id = NULL)
	{
		$this->datatables->select('CONCAT(firstname, lastname) AS p, name AS e, appointment, cancelled, noshow, completed,
									participation.id AS id, participant_id, experiment_id', FALSE);
		$this->datatables->from('participation');
		$this->datatables->join('participant', 'participant.id = participation.participant_id');
		$this->datatables->join('experiment', 'experiment.id = participation.experiment_id');

		// Exclude empty participations
		$this->datatables->where('(appointment IS NOT NULL OR cancelled = 1)');

		if (!empty($participant_id)) $this->datatables->where('participant_id', $participant_id);
		if (!empty($experiment_id)) $this->datatables->where('experiment_id', $experiment_id);

		$this->datatables->edit_column('p', '$1', 'participant_get_link_by_id(participant_id)');
		$this->datatables->edit_column('e', '$1', 'experiment_get_link_by_id(experiment_id)');
		$this->datatables->edit_column('appointment', '$1', 'output_datetime(appointment)');
		$this->datatables->edit_column('cancelled', '$1', 'img_tick(cancelled)');
		$this->datatables->edit_column('noshow', '$1', 'img_tick(noshow)');
		$this->datatables->edit_column('completed', '$1', 'img_tick(completed)');
		$this->datatables->edit_column('id', '$1', 'participation_actions(id)');

		$this->datatables->unset_column('participant_id');
		$this->datatables->unset_column('experiment_id');

		echo $this->datatables->generate();
	}

	public function risks_table($is_risk, $experiment_id)
	{
		$this->datatables->where('risk', $is_risk);
		$this->table(NULL, $experiment_id);
	}

	public function table_by_caller()
	{
		$experiment_ids = $this->callerModel->get_experiment_ids_by_caller(current_user_id());
		if (!empty($experiment_ids)) $this->datatables->where('experiment_id IN (' . implode(",", $experiment_ids) . ')');
		$this->table();
	}

	public function table_by_leader()
	{
		$experiment_ids = $this->leaderModel->get_experiment_ids_by_leader(current_user_id());
		if (!empty($experiment_ids)) $this->datatables->where('experiment_id IN (' . implode(",", $experiment_ids) . ')');
		$this->table();
	}
}
