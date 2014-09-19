<?php
class ParticipantModel extends CI_Model
{
	public function __construct()
	{
		parent::__construct();
	}

	/////////////////////////
	// CRUD-actions
	/////////////////////////

	/**
	 * Returns all participants as an array
	 * @param boolean $active If set to true, only active participants are included.
	 */
	public function get_all_participants($active = FALSE)
	{
		if ($active) $this->db->where('activated', TRUE);
		return $this->db->get('participant')->result();
	}

	/** Adds an participant to the DB */
	public function add_participant($participant)
	{
		$this->db->insert('participant', $participant);
		return $this->db->insert_id();
	}

	/** Updates the participant specified by the id with the details of the participant */
	public function update_participant($participant_id, $participant)
	{
		$this->db->where('id', $participant_id);
		$this->db->update('participant', $participant);
	}

	/** Deletes a particpant from the DB */
	public function delete_participant($participant_id)
	{
		// Delete references to impediments
		$this->db->delete('impediment', array('participant_id' => $participant_id));
		// Delete references to comments
		$this->db->delete('comment', array('participant_id' => $participant_id));
		// Delete references participations
		$this->db->delete('participation', array('participant_id' => $participant_id));

		$this->db->delete('participant', array('id' => $participant_id));
	}

	/** Returns the participant for an id */
	public function get_participant_by_id($participant_id)
	{
		return $this->db->get_where('participant', array('id' => $participant_id))->row();
	}

	/** Returns the participants given part of the name */
	public function find_participants_by_name($name)
	{
		$this->db->select('id, CONCAT(firstname, " ", lastname) AS name', FALSE);
		$this->db->like('firstname', $name);
		$this->db->or_like('lastname', $name);
		$participants = $this->db->get('participant')->result();

		$result = array();
		foreach ($participants as $participant)
		{
			array_push($result, array('label' => $participant->name, 'value' => $participant->id));
		}
		return $result;
	}

	/////////////////////////
	// Other actions
	/////////////////////////

	/** Returns the participants eligible for a (non-archived) experiment, they should be:
	 * - activated
	 * - not already participating in the experiment
	 * - not have been participating in an experiment that excludes this experiment
	 * - should have been participating in an experiment that is a prerequisite for this experiment
	 * - not currently having an impediment
	 * - not being a "risk" participant (depending on the sort of experiment)
	 * - within the age range of the experiment
	 */
	public function find_participants($experiment, $weeks_ahead = WEEKS_AHEAD)
	{
		if ($experiment->archived) return array();

		$prereqs = $this->relationModel->get_relation_ids_by_experiment($experiment->id, RelationType::Prerequisite, TRUE);
		$excludes = $this->relationModel->get_relation_ids_by_experiment($experiment->id, RelationType::Excludes, TRUE);

		$this->db->from('participant AS p', FALSE);
		$this->db->where('activated', TRUE);
		// not already participating (or cancelled)
		$this->db->where('NOT EXISTS
					(SELECT 1
					FROM 	participation AS part
					WHERE	part.participant_id = p.id
					AND 	(part.confirmed = 1 OR part.cancelled = 1)
					AND 	part.experiment_id = ' . $experiment->id . ')', NULL, FALSE);
		// should have been participating in an experiment that is a prerequisite for this experiment
		if (!empty($prereqs))
		{
			$this->db->where('EXISTS
					(SELECT 1
					FROM 	participation AS part
					WHERE	part.participant_id = p.id
					AND 	part.confirmed = 1
					AND 	part.experiment_id IN (' . implode(',', $prereqs) . '))', NULL, FALSE);
		}
		// not have been participating in an experiment that excludes this experiment
		if (!empty($excludes))
		{
			$this->db->where('NOT EXISTS
					(SELECT 1
					FROM 	participation AS part
					WHERE	part.participant_id = p.id
					AND 	part.confirmed = 1
					AND 	part.experiment_id IN (' . implode(',', $excludes) . '))', NULL, FALSE);
		}
		// not currently having an impediment
		$this->db->where('NOT EXISTS
					(SELECT 1
					FROM 	impediment AS imp
					WHERE	imp.participant_id = p.id
					AND 	(now() BETWEEN imp.from AND imp.to))', NULL, FALSE);
		// not being risk (depending on the sort of experiment)
		if ($experiment->dyslexic) 
		{
			$this->db->where('multilingual', FALSE);
		}
		if ($experiment->multilingual) 
		{
			$this->db->where('dyslexicparent IS NULL');
		}
		if (!($experiment->dyslexic || $experiment->multilingual)) 
		{
			$this->db->where('multilingual', FALSE);
			$this->db->where('dyslexicparent IS NULL');
		}

		// Get the results
		$participants = $this->db->get()->result();

		// Now check whether the participants are of correct age (TODO: maybe try and do this in SQL)
		$result = array();
		foreach ($participants as $participant)
		{
			$date = input_date('+' . $weeks_ahead. ' weeks');
			$age = explode(';', age_in_months_and_days($participant, $date));
			$months = $age[0];
			$days = $age[1];

			if ($months > $experiment->agefrommonths || ($months == $experiment->agefrommonths && $days >= $experiment->agefromdays))
			{
				if ($months < $experiment->agetomonths || ($months == $experiment->agetomonths && $days < $experiment->agetodays))
				{
					array_push($result, $participant);
				}
			}

		}

		return $result;
	}

	/** Finds all participants invitable for the given testsurvey */
	public function find_participants_by_testsurvey($testsurvey)
	{
		$this->db->from('participant AS p', FALSE);
		$this->db->where('activated', TRUE);

		// Participants should not already invited for this particular survey
		$this->db->where('NOT EXISTS
					(SELECT 1
					FROM 	testinvite AS ti
					WHERE	ti.participant_id = p.id
					AND 	ti.testsurvey_id  = ' . $testsurvey->id . ')', NULL, FALSE);

		// Check whether the age / the number of participations is according to the testsurvey
		if ($testsurvey->whensent == TestWhenSent::Months)
		{
			$months = $testsurvey->whennr;
			$this->db->where('TIMESTAMPDIFF(MONTH, dateofbirth, CURDATE()) >= ', $months - 1);
			$this->db->where('TIMESTAMPDIFF(MONTH, dateofbirth, CURDATE()) <= ', $months);
		}
		else
		{
			$count = $testsurvey->whennr;
			$this->db->where('(SELECT COUNT(*)
					FROM 	participation AS part
					WHERE	part.confirmed = 1
					AND 	part.participant_id = p.id) = ', $count, FALSE);			
		}

		return $this->db->get()->result();
	}

	/** Returns all dyslexic participants */
	public function get_dyslexic_participants()
	{
		$this->db->where('dyslexicparent IS NOT NULL');
		return $this->db->get('participant')->result();
	}

	/** Returns all multilingual participants */
	public function get_multilingual_participants()
	{
		$this->db->where('multilingual', TRUE);
		return $this->db->get('participant')->result();
	}

	/** Returns all 'risk' participants for an experiment */
	public function get_risk_participants($experiment)
	{
		if ($experiment->dyslexic) $this->db->where('dyslexicparent IS NOT NULL');
		if ($experiment->multilingual) $this->db->where('multilingual', $experiment->multilingual);
		return $this->db->get('participant')->result();
	}

	/** Returns all participants of a certain age in months */
	public function get_participants_of_age($months)
	{
		$this->db->where('activated', TRUE);
		$this->db->where('TIMESTAMPDIFF(MONTH, dateofbirth, CURDATE()) = ', $months);
		return $this->db->get('participant')->result();
	}

	/** Activates or deactivates the specified participant */
	public function set_activate($participant_id, $activated)
	{
		$this->db->where('id', $participant_id);
		$this->db->update('participant', array('activated' => $activated));
	}

	/** Returns the date on and experiment for which the given participant was last called */
	public function last_called($participant_id)
	{
		$this->db->where('participant_id', $participant_id);
		$this->db->where('nrcalls >', 0); // exclude new calls
		$this->db->order_by('lastcalled', 'DESC');
		$this->db->limit(1);
		$participation = $this->db->get('participation')->row();

		if (empty($participation)) return lang('never_called');

		$last_called = $participation->lastcalled;
		$experiment_id = $participation->experiment_id;
		$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);

		return sprintf(lang('last_call'), output_date($last_called), $experiment->name);
	}

	/** Returns the date on and the experiment in the participant last participated */
	public function last_experiment($participant_id)
	{
		$this->db->where('participant_id', $participant_id);
		$this->db->where('confirmed', TRUE);	// only confirmed
		$this->db->order_by('appointment', 'DESC');
		$this->db->limit(1);
		$participation = $this->db->get('participation')->row();

		if (empty($participation)) return lang('never_participated');

		$last_exp = $participation->appointment;
		$experiment_id = $participation->experiment_id;
		$experiment = $this->experimentModel->get_experiment_by_id($experiment_id);

		return sprintf(lang('last_exp'), output_date($last_exp), $experiment->name);
	}
}