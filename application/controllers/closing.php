<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class Closing extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->authenticate->redirect_except();
        reset_language(current_language());

        $this->form_validation->set_error_delimiters('<label class="error">', '</label>');
    }

    public function index($include_past = FALSE)
    {
        $add_url = array('url' => 'closing/add', 'title' => lang('add_closing'));
        $past_url = closing_past_url($include_past);

        create_closing_table();
        $data['ajax_source'] = 'closing/table/' . $include_past;
        $data['page_title'] = lang('closings');
        $data['action_urls'] = array($add_url, $past_url);

        $this->load->view('templates/header', $data);
        $this->authenticate->authenticate_redirect('templates/list_view', $data, UserRole::Admin);
        $this->load->view('templates/footer');
    }

    /** Page to add a closing */
    public function add()
    {
        $data['page_title'] = lang('closings');
        $data['locations'] = location_options($this->locationModel->get_all_locations());

        $this->load->view('templates/header', $data);
        $this->authenticate->authenticate_redirect('closing_add_view', $data, UserRole::Admin);
        $this->load->view('templates/footer');
    }

    /** Adds a closing for a participant */
    public function add_submit()
    {
        // Run validation
        if (!$this->validate_closing())
        {
            // Show form again with error messages
            $this->add();
        }
        else
        {
            // If succeeded, insert data into database
            $closing = $this->post_closing();
            $this->closingModel->add_closing($closing);

            flashdata(lang('closing_added'));
            redirect('/closing', 'refresh');
        }
    }

    /** Deletes the specified closing. */
    public function delete($closing_id)
    {
        $this->closingModel->delete_closing($closing_id);
        flashdata(lang('closing_deleted'));
        redirect($this->agent->referrer(), 'refresh');
    }

    /////////////////////////
    // Form handling
    /////////////////////////

    /** Validates an closing */
    private function validate_closing()
    {
        $this->form_validation->set_rules('location', lang('location'), 'callback_not_zero');
        $this->form_validation->set_rules('from_date', lang('from_date'), 'trim|required|callback_check_within_bounds');
        $this->form_validation->set_rules('to_date', lang('to_date'), 'trim|required|callback_check_within_bounds');
        $this->form_validation->set_rules('comment', lang('comment'), 'trim');

        return $this->form_validation->run();
    }

    /** Posts the data for an closing */
    private function post_closing()
    {
        return array(
            'location_id'       => $this->input->post('location'),
            'from'              => input_date($this->input->post('from_date')),
            'to'                => input_date($this->input->post('to_date')),
            'comment'           => $this->input->post('comment'),
            );
    }

    /////////////////////////
    // Callbacks
    /////////////////////////

    /** Checks whether the given date is within bounds of an existing closing for this location */
    public function check_within_bounds($date)
    {
        $location_id = $this->input->post('location');
        if ($this->closingModel->within_bounds(input_date($date), $location_id))
        {
            $this->form_validation->set_message('check_within_bounds', lang('closing_within_bounds'));
            return FALSE;
        }
        return TRUE;
    }

    /** Checks whether the given parameter is higher than 0 */
    public function not_zero($value)
    {
        if (intval($value) <= 0)
        {
            $this->form_validation->set_message('not_zero', lang('isset'));
            return FALSE;
        }
        return TRUE;
    }

    /////////////////////////
    // Table
    /////////////////////////

    public function table($include_past = FALSE)
    {
        $this->datatables->select('name, from, comment, closing.id AS id, location_id');
        $this->datatables->from('closing');
        $this->datatables->join('location', 'location.id = closing.location_id');

        if (!$include_past) $this->db->where('to >=', input_date());

        $this->datatables->edit_column('name', '$1', 'location_get_link_by_id(location_id)');
        $this->datatables->edit_column('from', '$1', 'closing_dates_by_id(id)');
        $this->datatables->edit_column('comment', '$1', 'comment_body(comment, 30)');
        $this->datatables->edit_column('id', '$1', 'closing_actions(id)');

        $this->datatables->unset_column('location_id');

        echo $this->datatables->generate();
    }
}
