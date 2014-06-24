<?php
class Comment extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();
		reset_language(current_language());
	}

	/////////////////////////
	// CRUD-actions
	/////////////////////////

	/** Specifies the contents of the default page. */
	public function index($priority_only = FALSE)
	{
		$prio_url = array('url' => 'comment/priority', 'title' => lang('view_high_priority'));
		$no_prio_url = array('url' => 'comment', 'title' => lang('view_low_priority'));
		$p_url = $priority_only ? $no_prio_url : $prio_url;

		create_comment_table();
		$data['ajax_source'] = 'comment/table/' . $priority_only;
		$data['page_title'] = lang('comments');
		$data['action_urls'] = array($p_url);

		$this->load->view('templates/header', $data);
		$this->authenticate->authenticate_redirect('templates/list_view', $data, UserRole::Admin);
		$this->load->view('templates/footer');
	}

	/** Adds a comment for the specified participant */
	public function add_submit($participant_id)
	{
		// Run validation
		if (!$this->validate_comment()) 
		{
			// Show form again with error messages
			flashdata(validation_errors(), FALSE, 'comment_message');
			redirect($this->agent->referrer(), 'refresh');
		}
		else 
		{
			// If succeeded, insert data into database
			$comment = $this->post_comment($participant_id);
			$this->commentModel->add_comment($comment);

			flashdata(lang('comment_added'), TRUE, 'comment_message');
			redirect($this->agent->referrer(), 'refresh');
		}
	}

	/** Deletes the specified comment, and returns to previous page */
	public function delete($comment_id)
	{
		$this->commentModel->delete_comment($comment_id);
		flashdata(lang('comment_deleted'), TRUE, 'comment_message');
		redirect($this->agent->referrer(), 'refresh');
	}

	/////////////////////////
	// Other actions
	/////////////////////////

	/** Prioritizes (or downgrades the priority) a comment */
	public function prioritize($comment_id, $up = TRUE)
	{
		$this->commentModel->prioritize($comment_id, $up);
		$message = $up ? lang('comment_prio_up') : lang('comment_prio_down');
		flashdata($message, TRUE, 'comment_message');
		redirect($this->agent->referrer(), 'refresh');
	}

	/////////////////////////
	// Other views
	/////////////////////////

	/** Specifies the contents of tthe page with only priority items. */
	public function priority()
	{
		$this->index(TRUE);
	}

	/** Specifies the content of a page with the contents for a specific participant */
	public function participant($participant_id)
	{
		$participant = $this->participantModel->get_participant_by_id($participant_id);

		create_comment_table();
		$data['ajax_source'] = 'comment/table/0/' . $participant->id;
		$data['page_title'] = sprintf(lang('comments_for'), name($participant));

		$this->load->view('templates/header', $data);
		$this->authenticate->authenticate_redirect('templates/list_view', $data);
		$this->load->view('templates/footer');
	}

	/////////////////////////
	// Form handling
	/////////////////////////

	/** Validates a comment */
	private function validate_comment()
	{
		$this->form_validation->set_rules('comment', lang('comment'), 'trim|required');

		return $this->form_validation->run();
	}

	/** Posts the data for a comment */
	private function post_comment($participant_id)
	{
		return array(
				'body'				=> $this->input->post('comment'),
				'participant_id' 	=> $participant_id,
				'user_id'		 	=> current_user_id()
		);
	}

	/////////////////////////
	// Table
	/////////////////////////

	public function table($priority_only = FALSE, $participant_id = NULL)
	{
		$this->datatables->select('CONCAT(firstname, lastname) AS p, body, timecreated, username,
			comment.id AS id, participant_id, user_id', FALSE);
		$this->datatables->from('comment');
		$this->datatables->join('participant', 'participant.id = comment.participant_id');
		$this->datatables->join('user', 'user.id = comment.user_id');

		if ($priority_only) $this->datatables->where('priority', TRUE);
		if (!empty($participant_id)) $this->datatables->where('participant_id', $participant_id);

		$this->datatables->edit_column('p', '$1', 'participant_get_link_by_id(participant_id)');
		$this->datatables->edit_column('timecreated', '$1', 'output_date(timecreated)');
		$this->datatables->edit_column('username', '$1', 'user_get_link_by_id(user_id)');
		$this->datatables->edit_column('id', '$1', 'comment_actions(id)');
		
		$this->datatables->unset_column('participant_id');
		$this->datatables->unset_column('user_id');

		echo $this->datatables->generate();
	}
	
	public function table_by_user($user_id)
	{
		$this->datatables->where('user_id', $user_id);
		$this->table();
	}
}
