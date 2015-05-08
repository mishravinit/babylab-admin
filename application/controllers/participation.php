<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Participations can be in 7 possible stages:
 *
 * confirmed	cancelled	no-show	completed	description
 * 0			0			0		0			unconfirmed (called but no response)
 * 1			0			0		0			confirmed, not yet completed
 * 1			0			0		1			confirmed, completed
 * 1			0			1		0			confirmed, but no-show
 * 1			1			0		0			confirmed, cancelled later
 * 0			1			0		0			cancelled upfront
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

		$this->form_validation->set_error_delimiters('<label class="error">', '</label>');
	}

	/////////////////////////
	// CRUD-actions
	/////////////////////////

	/** Specifies the contents of the default page. */
	public function index()
	{
		switch (current_role())
		{
			case UserRole::Admin: 
				create_participation_table();
				$add_url = array('url' => 'participation/add', 'title' => lang('ad_hoc_participation'));
				$source = 'participation/table/'; 
				$data['action_urls'] = array($add_url); 
				break;
			case UserRole::Leader: 
				create_participation_leader_table();
				$source = 'participation/table_by_leader/'; 
				break;
			default: 
				create_participation_table();
				$source = 'participation/table_by_caller/'; 
				break;
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
	public function delete($participation_id, $referrer = NULL)
	{
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$this->participationModel->delete_participation($participation_id);

		flashdata(sprintf(lang('part_deleted'), name($participant), $experiment->name));
		redirect($referrer ? $referrer : $this->agent->referrer, 'refresh');
	}

	///////////////////////////////////////////
	// Add participation (admin only)
	///////////////////////////////////////////

	/** Restrict acces to admin only **/
	private function admin_only()
	{
		if (current_role() != UserRole::Admin)
		{
			flashdata(lang('not_authorized'));
			redirect('/participation/', 'refresh');
		}
	}

	/** Add view for ad hoc participation */
	public function add()
	{
		$this->admin_only();

		$participants = $this->participantModel->get_all_participants();
		$experiments = $this->experimentModel->get_all_experiments();
		$leaders = $this->userModel->get_all_leaders() + $this->userModel->get_all_admins();

		$data['page_title'] = lang('ad_hoc_participation');
		$data['action'] = 'participation/add_submit';

		$data['experiments'] = experiment_options($experiments);
		$data['participants'] = participant_options($participants);
		$data['leaders'] = leader_options($leaders);

		$this->load->view('templates/header', $data);
		$this->load->view('participation_add_view', $data);
		$this->load->view('templates/footer');
	}

	/** Adds an ad hoc participation */
	public function add_submit()
	{
		$this->admin_only();

		// Get POST data
		$participant = $this->participantModel->get_participant_by_id($this->input->post('participant'));
		$experiment = $this->experimentModel->get_experiment_by_id($this->input->post('experiment'));

		// Run validation
		if (!$this->validate_experiment())
		{
			// Show form again with error messages
			$this->add();
		}
		else
		{
			// No errors, get the current participation
			$participation = $this->participationModel->get_participation($experiment->id, $participant->id);
				
			if (empty($participation))
			{
				// No participation exists yet, create a new one
				$participation_id = $this->participationModel->create_participation($experiment, $participant);
			} 
			else if ($participation->status == ParticipationStatus::Unconfirmed)
			{
				// A non-completed participation exists, use this one
				$participation_id = $participation->id;
			}
			else 
			{
				// Participation already exists and is completed, error.
				flashdata(sprintf(lang('participation_exists'), name($participant), $experiment->name), FALSE);
				redirect('participation/add');
			}

			$call_id = $this->callModel->create_call($participation_id);
			redirect('call/confirm/' . $call_id . '/' . 
				$this->input->post('leader') . '/' . 
				strtotime($this->input->post('appointment')), 'refresh');
		}
	}

	private function validate_experiment()
	{
		// Require experiment and participant to be selected
		$this->form_validation->set_rules('experiment', lang('experiment'), 'callback_not_default');
		$this->form_validation->set_rules('participant', lang('participant'), 'callback_not_default');
		$this->form_validation->set_rules('appointment', lang('appointment'), 'trim|required');

		return $this->form_validation->run();
	}

	/** Check if the dropdown option selected isn't the default (-1) */
	public function not_default($value)
	{
		if ($value == -1)
		{
			$this->form_validation->set_message('not_default', lang('isset'));
			return FALSE;
		} 
		else 
		{
			return TRUE;
		}
	}

	/////////////////////////
	// Other views
	/////////////////////////

	/** Shows the page for a specific experiment */
	public function experiment($experiment_id)
	{
		switch (current_role())
		{
			case UserRole::Leader: 
				create_participation_leader_table();
				$source = 'participation/table_by_leader/' . $experiment_id; 
				break;
			default: 
				create_participation_table();
				$add_url = array('url' => 'participation/add', 'title' => lang('ad_hoc_participation'));
				$source = 'participation/table/0/' . $experiment_id; 
				$data['action_urls'] = array($add_url); 
				break;
		}

		$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);
		$data['page_title'] = sprintf(lang('participations_for'), $experiment->name);
		$data['ajax_source'] = $source;

		$this->load->view('templates/header', $data);
		$this->load->view('templates/list_view', $data);
		$this->load->view('templates/footer');
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
		$leaders = $this->leaderModel->get_leader_users_by_experiments($experiment_id);
		$first_visit = count($this->participationModel->get_participations_by_participant($participant_id, TRUE)) == 0;

		// Retrieve or create participation record
		$participation = $this->participationModel->get_participation($experiment_id, $participant_id);
		if ($participation)
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

		// Find possible combination experiment and check if participation exists
		$combinations = $this->relationModel->get_relation_ids_by_experiment($experiment->id, RelationType::Combination);
		$comb_exp = $combinations ? $this->experimentModel->get_experiment_by_id($combinations[0]) : FALSE;
		$comb_part = $this->participationModel->get_participation($comb_exp->id, $participant_id);
		if ($comb_part) $comb_exp = FALSE;

		// Create page data
		$data = get_min_max_days($participant, $experiment);
		$data['participant'] = $participant;
		$data['experiment'] = $experiment;
		$data['participation'] = $participation;
		$data['participation_id'] = $participation_id;
		$data['leaders'] = leader_options($leaders);
		$data['call_id'] = $call_id;
		$data['previous_call'] = $previous_call;
		$data['comment_size'] = count($comments);
		$data['impediment_table'] = create_impediment_table($impediments);
		$data['impediment_size'] = count($impediments);
		$data['last_experiment'] = $this->participantModel->last_experiment($participant_id);
		$data['last_called'] = $this->participantModel->last_called($participant_id);
		$data['nr_participations'] = count($participations);
		$data['verify_languages'] = language_check($participant);
		$data['verify_dyslexia'] = dyslexia_check($participant);
		$data['first_visit'] = $first_visit;
		$data['combination_exp'] = $comb_exp;
		$data['page_title'] = sprintf(lang('call_participant'), name($participant));

		$this->load->view('templates/header', $data);
		$this->load->view('participation_call', $data);
		$this->load->view('templates/footer');
	}
	
	/**
	 * Shows the calendar in a new popup window
	 */
	public function show_calendar()
	{
		$this->load->view('participation_call', $data);
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

		$data['page_info'] = lang('no_shows_info');
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

		$data['page_info'] = lang('interruptions_info');
		$data['table'] = create_participation_counter_table($participations, lang('interruptions'));
		$data['sort_column'] = 1; // sort on count of interruptions
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
			// If succeeded, insert data into database and send e-mail
			$appointment = input_datetime($this->input->post('appointment'));
			$this->participationModel->reschedule($participation_id, $appointment);
			$flashdata = br() . $this->send_reschedule_email($participation_id);
			flashdata(sprintf(lang('part_rescheduled'), name($participant), $experiment->name) . $flashdata);
			redirect('/participation/experiment/' . $experiment->id, 'refresh');
		}
	}

	/** Cancels a participation */
	public function cancel($participation_id)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);

		$data['participation'] = $participation;
		$data['participant'] = $participant;
		$data['experiment'] = $experiment;
		$data['referrer'] = $this->agent->referrer();

		$this->load->view('templates/header', $data);
		$this->load->view('participation_cancel', $data);
		$this->load->view('templates/footer');
	}

	/** Submits the cancellation (or deletion) of a participation */
	public function cancel_submit($participation_id)
	{
		$referrer = $this->input->post('referrer'); 
		$this->send_cancellation_email($participation_id);

		if ($this->input->post('delete')) 
		{
			$this->delete($participation_id, $referrer);
		}
		else 
		{
			$this->participationModel->cancel($participation_id, FALSE);

			$participant = $this->participationModel->get_participant_by_participation($participation_id);
			$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
			flashdata(sprintf(lang('part_cancelled'), name($participant), $experiment->name));

			redirect($referrer, 'refresh');
		}
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
	public function completed($participation_id, $pp_comment = '', $tech_comment = '')
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

		// Empty the comments, so one can re-enter them at wish
		$data['pp_comment'] = $pp_comment;
		$data['tech_comment'] = $tech_comment;

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
		$this->form_validation->set_rules('excluded', lang('excluded'), 'required');
		$this->form_validation->set_rules('excluded_reason', lang('excluded_reason'), 
			$this->input->post('excluded') ? 'callback_not_default' : 'trim');
		$this->form_validation->set_rules('comment', lang('comment'), 'trim|required');
		$this->form_validation->set_rules('pp_comment', lang('pp_comment'), 'trim');
		$this->form_validation->set_rules('tech_comment', lang('tech_comment'), 'trim');

		// Run validation
		if (!$this->form_validation->run())
		{
			// If not succeeded, return to previous page
			$pp_comment = $this->input->post('pp_comment');
			$tech_comment = $this->input->post('tech_comment');
			$this->completed($participation_id, $pp_comment, $tech_comment);
		}
		else
		{
			// If succeeded, insert data into database
			$r = $this->input->post('excluded_reason');
			$participation = array(
				'part_number' 	=> $this->input->post('part_number'),
				'interrupted' 	=> $this->input->post('interrupted'),
				'excluded' 		=> $this->input->post('excluded'),
				'excluded_reason' => $r == '-1' ? NULL : $r,
				'comment'  		=> $this->input->post('comment')
			);
			$this->participationModel->completed($participation_id, $participation);

			// Add (possible) comment
			$comment = $this->post_comment($participant->id);
			if ($comment) $this->commentModel->add_comment($comment);
			
			// Mail (possible) technical comment
			$tech_comment = $this->input->post('tech_comment');
			if ($tech_comment)
			{
				$this->participationModel->add_tech_message($participation_id, $tech_comment);
				$this->send_technical_email($participation_id, $tech_comment);
			}

			// Deactivate participant (possibly)
			if ($this->input->post('cancelled_complete'))
			{
				$this->participantModel->deactivate($participant->id, DeactivateReason::AfterExp);
			}

			flashdata(sprintf(lang('part_completed'), name($participant), $experiment->name));
			redirect('/participation/experiment/' . $experiment->id, 'refresh');
		}
	}

	/////////////////////////
	// Mails
	/////////////////////////

	/** Send a mail to participant (and leaders) to signal appointment has been rescheduled */
	private function send_reschedule_email($participation_id)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
		$leader_emails = $this->leaderModel->get_leader_emails_by_experiment($experiment->id);

		$message = email_replace('mail/reschedule', $participant, $participation, $experiment);

		$this->email->clear();
		$this->email->from(FROM_EMAIL, FROM_EMAIL_NAME);
		$this->email->to(in_development() ? TO_EMAIL_OVERRIDE : $participant->email);
		$this->email->cc(in_development() ? TO_EMAIL_OVERRIDE : $leader_emails); 
		$this->email->subject('Babylab Utrecht: Uw afspraak is verzet');
		$this->email->message($message);
		$this->email->send();

		return sprintf(lang('reschedule_sent'), $participant->email);
	}

	/** Send a mail to the leaders to signal appointment has been cancelled */
	private function send_cancellation_email($participation_id)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
		$leader_emails = $this->leaderModel->get_leader_emails_by_experiment($experiment->id);

		$message = email_replace('mail/cancel', $participant, $participation, $experiment);

		$this->email->clear();
		$this->email->from(FROM_EMAIL, FROM_EMAIL_NAME);
		$this->email->to(in_development() ? TO_EMAIL_OVERRIDE : $leader_emails); 
		$this->email->subject('Babylab Utrecht: Afspraak verwijderd');
		$this->email->message($message);
		$this->email->send();
	}
	
	/** Send a mail to the technical folks */ 
	private function send_technical_email($participation_id, $tech_comment)
	{
		$participation = $this->participationModel->get_participation_by_id($participation_id);
		$participant = $this->participationModel->get_participant_by_participation($participation_id);
		$experiment = $this->participationModel->get_experiment_by_participation($participation_id);
		
		$message = email_replace('mail/tech_comment', $participant, $participation, $experiment, NULL, NULL, FALSE, $tech_comment);
		
		$this->email->clear();
		$this->email->from(FROM_EMAIL, FROM_EMAIL_NAME);
		$this->email->to(in_development() ? TO_EMAIL_OVERRIDE : LAB_EMAIL);
		$this->email->subject('Babylab Utrecht: Technisch probleem');
		$this->email->message($message);
		$this->email->send();
	}

	/////////////////////////
	// Form handling
	/////////////////////////

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
		$this->datatables->select('CONCAT(participant.firstname, " ", participant.lastname) AS p, 
									name AS e, appointment, username, 
									cancelled, noshow, completed,
									participation.id AS id, participant_id, experiment_id', FALSE);
		$this->datatables->from('participation');
		$this->datatables->join('participant', 'participant.id = participation.participant_id');
		$this->datatables->join('experiment', 'experiment.id = participation.experiment_id');
		$this->datatables->join('user', 'user.id = participation.user_id_leader', 'LEFT');

		// Exclude empty participations
		$this->datatables->where('(appointment IS NOT NULL OR cancelled = 1)');

		if ($participant_id) $this->datatables->where('participant_id', $participant_id);
		if ($experiment_id) $this->datatables->where('experiment_id', $experiment_id);

		$this->datatables->edit_column('p', '$1', 'participant_get_link_by_id(participant_id)');
		$this->datatables->edit_column('e', '$1', 'experiment_get_link_by_id(experiment_id)');
		$this->datatables->edit_column('appointment', '$1', 'output_datetime(appointment)');
		$this->datatables->edit_column('cancelled', '$1', 'img_tick(cancelled, 0)');
		$this->datatables->edit_column('noshow', '$1', 'img_tick(noshow, 0)');
		$this->datatables->edit_column('completed', '$1', 'img_tick(completed, 0)');
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
		if ($experiment_ids) $this->datatables->where('experiment_id IN (' . implode(',', $experiment_ids) . ')');
		$this->table();
	}

	/**
	 * Special table for leaders, with another set of columns and actions
	 */
	public function table_by_leader($experiment_id = NULL)
	{
		$experiment_ids = $this->leaderModel->get_experiment_ids_by_leader(current_user_id());

		$this->datatables->select('name AS e, part_number, risk, appointment, appointment AS age, interrupted, excluded, comment,
									participation.id AS id, participant_id, experiment_id', FALSE);
		$this->datatables->from('participation');
		$this->datatables->join('experiment', 'experiment.id = participation.experiment_id');

		// Exclude empty participations
		$this->datatables->where('(appointment IS NOT NULL OR cancelled = 1)');

		if ($experiment_ids) $this->datatables->where('experiment_id IN (' . implode(',', $experiment_ids) . ')');
		if ($experiment_id) $this->datatables->where('experiment_id', $experiment_id);

		$this->datatables->edit_column('e', '$1', 'experiment_get_link_by_id(experiment_id)');
		$this->datatables->edit_column('appointment', '$1', 'output_datetime(appointment)');
		$this->datatables->edit_column('risk', '$1', 'img_tick(risk, 0)');
		$this->datatables->edit_column('age', '$1', 'age_in_md_by_id(participant_id, age)');
		$this->datatables->edit_column('interrupted', '$1', 'img_tick(interrupted, 0)');
		$this->datatables->edit_column('excluded', '$1', 'img_tick(excluded, 0)');
		$this->datatables->edit_column('comment', '$1', 'comment_body(comment, 50)');
		$this->datatables->edit_column('id', '$1', 'participation_actions(id)');

		$this->datatables->unset_column('experiment_id');

		echo $this->datatables->generate();
	}
}
