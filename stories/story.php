<?php

$fate = new Actor(new SimpleXMLElement('<actor><name>Fate</name><description>Accidents and the forces of nature</description><pronoun>itself</pronoun></actor>'));

class Story {

	public $title;
	public $setting;
	public $cast;
	public $steps;
	public $executed_steps;
	public $orderings;
	public $causal_links;
	public $intention_frames;
	public $conflict_links;
	
	public function __construct($story_xml_file_name){
		$xml = simplexml_load_file($story_xml_file_name);
		// Title
		$this->title = (string) $xml->title;
		// Setting
		$this->setting = (string) $xml->setting;
		// Cast
		$this->cast = array();
		foreach($xml->cast->actor as $actor)
			$this->cast[] = new Actor($actor);
		global $fate;
		$this->cast[] = $fate;
		// Start step
		$start_step = new Step($xml->steps->startstep, $this, 0);
		$start_step->name = 'start';
		$start_step->executed = true;
		$this->steps = array($start_step);
		$this->executed_steps = array($start_step);
		// Plan steps
		$time = 1;
		foreach($xml->steps->step as $step){
			$new_step = new Step($step, $this, $time);
			$this->steps[] = $new_step;
			if($new_step->executed && !$new_step->persist){
				$this->executed_steps[] = $new_step;
				$time++;
			}
		}
		// End step
		$end_step = new Step($xml->steps->endstep, $this, $time);
		$end_step->name = 'end';
		$end_step->executed = true;
		$this->steps[] = $end_step;
		$this->executed_steps[] = $end_step;
		// Partial Ordering
		$this->orderings = new Orderings($xml->orderings);
		// Causal links
		$this->causal_links = array();
		foreach($xml->causal_links->causal_link as $causal_link)
			$this->causal_links[] = new CausalLink($causal_link, $this);
		// Intention frames
		$this->intention_frames = array();
		foreach($xml->intention_frames->frame as $frame)
			$this->intention_frames[] = new IntentionFrame($frame, $this);
		// Put all happenings into an intention frame for the environment.
		$fate_frame = new FateIntentionFrame($this);
		$intention_frames[] = $fate_frame;
		// Order all persistance steps after non-persistance steps.
		for($i=0; $i<count($this->steps)-1 && !$this->steps[$i]->persist; $i++);
		if($this->steps[$i]->persist){
			$first_persist = $this->steps[$i];
			foreach($this->steps as $step){
				if($step->persist && $step != $first_persist)
					$this->orderings->impose_ordering($first_persist, $step);
				elseif(!$step->persist)
					$this->orderings->impose_ordering($step, $first_persist);
			}
		}
		// Totally order each character's steps.
		foreach($this->cast as $actor){
			$actor_steps = array();
			foreach($this->steps as $step)
				if(in_array($actor, $step->actors))
					$actor_steps[] = $step;
			for($i=0; $i<count($actor_steps)-1; $i++)
				$this->orderings->impose_ordering($actor_steps[$i], $actor_steps[$i+1]);
		}
		// Make sure that the total ordering of steps is consistent with the partial ordering.
		for($i=0; $i<count($this->steps)-2; $i++)
			if(!$this->orderings->can_come_before($this->steps[$i], $this->steps[$i+1]))
				//throw new Exception('The step "'.$this->steps[$i].'" cannot be ordered before "'.$this->steps[$i+1].'" in the total ordering, because this ordering is invalid according to the partial ordering.');
				$this->orderings->impose_ordering($this->steps[$i], $this->steps[$i+1]);
		// Make sure every precondition of every step is satisfied.
		foreach($this->steps as $step){
			foreach($step->preconditions as $precondition){
				$fail = true;
				foreach($this->get_causal_links_by_label($precondition) as $causal_link){
					if($causal_link->head == $step){
						$fail = false;
						break;
					}
				}
				if($fail) throw new Exception('The precondition "'.$precondition.'" of step "'.$step.'" is not satisfied by any causal link.');
			}
		}
		// Make sure every intention has an associated intention frame.
		foreach($this->steps as $step){
			foreach($step->intentions as $intention){
				$fail = true;
				foreach($this->intention_frames as $frame){
					if($intention->actor == $frame->actor && $intention->goal->equals($frame->goal) && $step == $frame->motivating_step){
						$fail = false;
						break;
					}
				}
				if($fail) throw new Exception('The intentional effect "'.$intention.'" of step "'.$step.'" does not have an associated intention frame.');
			}
		}
		// Make sure every intentional step is a member of some intention frame.
		foreach($this->steps as $step){
			foreach($step->actors as $actor){
				if($actor != $fate){
					$fail = true;
					foreach($step->frames as $frame){
						if($actor == $frame->actor && in_array($step, $frame->steps)){
							$fail = false;
							break;
						}
					}
				}
				if($fail) throw new Exception('The step "'.$step.'" must be intended by "'.$actor.'", but it is not a member of any intention frame for "'.$actor.'".');
			}
		}
		// Make sure that all threatened causal links are conflict links.
		$this->conflict_links = array();
		foreach($this->causal_links as $causal_link){
			foreach($this->steps as $step){
				// If a step threatens a causal link...
				$threats = get_threats($causal_link, $step, $this);
				foreach($threats as $threat){
					// For each frame that the threat belongs to...
					foreach($step->frames as $threat_frame){
						// For each frame that the head step belongs to...
						foreach($causal_link->head->frames as $head_frame){
							// If the duration of the conflict would be 0, impose an ordering to prevent it.
							if($head_frame->end_time <= $threat_frame->start_time)
								$this->orderings->impose_ordering($head_frame->get_last_executed_step(), $threat_frame->motivating_step);
							elseif($threat_frame->end_time <= $head_frame->start_time)
								$this->orderings->impose_ordering($threat_frame->get_last_executed_step(), $head_frame->motivating_step);
							// Otherwise, create a new conflict link.
							else
								$this->conflict_links[] = new ConflictLink($causal_link, $head_frame, $step, $threat_frame);
						}
					}
				}
			}	
		}
	}
	
	public function get_start_step(){
		return $this->steps[0];
	}
	
	public function get_end_step(){
		return $this->steps[count($this->steps) - 1];
	}
	
	public function get_actor($name){
		foreach($this->cast as $actor)
			if($actor->name == $name)
				return $actor;
		throw new Exception('The story has no actor named "'.$name.'".');
	}
	
	public function get_step_by_name($name){
		foreach($this->steps as $step)
			if($step->name == $name)
				return $step;
		throw new Exception('The story has no step named "'.$name.'".');
	}
	
	public function get_steps_by_effect($effect){
		$steps = array();
		foreach($this->steps as $step){
			foreach($step->effects as $e){
				if($effect->equals($e)){
					$steps[] = $step;
					break;
				}
			}
		}
		return $steps;
	}
	
	public function get_causal_links_by_label($label){
		$causal_links = array();
		foreach($this->causal_links as $causal_link)
			if($causal_link->label->equals($label))
				$causal_links[] = $causal_link;
		if(count($causal_links) == 0)
			throw new Exception('The story has no causal links with the label "'.$label.'".');
		else return $causal_links;
	}
	
	public function get_causal_links_by_head_step($head){
		$causal_links = array();
		foreach($this->causal_links as $causal_link)
			if($causal_link->head == $head)
				$causal_links[] = $causal_link;
		return $causal_links;
	}
	
	public function get_actor_plan($actor, $time){
		$actor_plan = array();
		foreach($this->steps as $step){
			$include = false;
			if($step->time > $time && in_array($actor, $step->actors)){
				foreach($step->frames as $frame){
					if($frame->actor == $actor && $frame->motivating_step->executed && $frame->motivating_step->time <= $time && $frame->end_time > $time){
						$include = true;
						break;
					}
				}
			}
			if($include) $actor_plan[] = $step;
		}
		return $actor_plan;
	}
}

class Actor {

	public $name;
	public $description;
	public $pronoun;
	
	public function __construct($actor_xml){
		$this->name = (string) $actor_xml->name;
		$this->description = (string) $actor_xml->description;
		$this->pronoun = (string) $actor_xml->pronoun;
	}
	
	public function __toString(){
		return $this->name;
	}
}

class Step {
	
	public $name;
	public $executed;
	public $persist;
	public $actors;
	private $description;
	private $personal_description;
	public $preconditions;
	public $effects;
	public $intentions;
	public $frames;
	public $time;
	
	public function __construct($step_xml, $story, $time){
		global $fate;
		// Name
		$this->name = (string) $step_xml->name;
		if($this->name == 'start')
			throw new Exception('Only the start step can be named "start".');
		if($this->name == 'end')
			throw new Exception('Only the end step can be named "end".');
		// Executed
		$this->executed = false;
		if($step_xml['executed'] == 'true')
			$this->executed = true;
		// Persist
		$this->persist = false;
		if($step_xml['persist'] == 'true')
			$this->persist = true;
		// Actors
		$this->actors = array();
		if($step_xml->actors)
			foreach($step_xml->actors->actor as $actor)
				$this->actors[] = $story->get_actor((string) $actor);
		if(count($this->actors) == 0)
			$this->actors[] = $fate;
		// Descriptions
		$this->description = (string) $step_xml->descriptions->general;
		$this->personal_description = array();
		if($step_xml->descriptions->personal){
			foreach($step_xml->descriptions->personal as $personal){
				$actor_name = (string) $personal['actor'];
				$this->personal_description[$actor_name] = (string) $personal;
			}
		}
		// Preconditions
		$this->preconditions = array();
		if($step_xml->preconditions)
			foreach($step_xml->preconditions->proposition as $proposition)
				$this->preconditions[] = new Proposition($proposition);
		// Effects
		$this->effects = array();
		if($step_xml->effects)
			foreach($step_xml->effects->proposition as $proposition)
				$this->effects[] = new Proposition($proposition);
		// Intentions
		$this->intentions = array();
		if($step_xml->effects)
			foreach($step_xml->effects->intention as $intention)
				$this->intentions[] = new Intention($intention, $story);
		// Frames (populated later)
		$this->frames = array();
		// Time
		$this->time = $time;
	}
	
	public function describe($actor=null){
		if($actor){
			if(array_key_exists($actor->name, $this->personal_description))
				return $this->personal_description[$actor->name];
			else return $this->description;
		}
		else return $this->description;
	}
	
	public function is_start(){
		return $this->name == 'start';
	}
	
	public function is_end(){
		return $this->name == 'end';
	}
	
	public function __toString(){
		return $this->name;
	}
}

class Proposition {

	public $sign;
	public $name;
	
	public function __construct($proposition_xml){
		$this->sign = true;
		if($proposition_xml['sign'] == 'false')
			$this->sign = false;
		$this->name = (string) $proposition_xml;
	}
	
	public function equals($other_proposition){
		return $this->sign == $other_proposition->sign && $this->name == $other_proposition->name;
	}
	
	public function negate(){
		if($this->sign)
			return new Proposition(new SimpleXMLElement('<proposition sign="false">'.$this->name.'</proposition>'));
		else return new Proposition(new SimpleXMLElement('<proposition sign="true">'.$this->name.'</proposition>'));
	}
	
	public function __toString(){
		if($this->sign) return $this->name;
		else return '&not;'.$this->name;
	}
}

class Intention {

	public $actor;
	public $goal;
	public $frame;
	
	public function __construct($intention_xml, $story){
		$this->actor = $story->get_actor((string) $intention_xml->actor);
		$this->goal = new Proposition($intention_xml->goal);
	}
	
	public function __toString(){
		return $this->actor.' intends '.$this->goal;
	}
}

class Orderings {

	public $adjacency_lists;
	public $cache;
	
	public function __construct($orderings_xml){
		$this->adjacency_lists = array();
		if($orderings_xml->ordering){
			foreach($orderings_xml->ordering as $ordering)
				$this->impose_ordering($ordering->before, $ordering->after);
		}
	}
	
	public function impose_ordering($before, $after){
		$this->cache = null;
		$before = (string) $before;
		$after = (string) $after;
		if($this->can_come_before($before, $after)){			
			if(!array_key_exists($before, $this->adjacency_lists))
				$this->adjacency_lists[$before] = array();
			if(!in_array($after, $this->adjacency_lists[$before]))
				$this->adjacency_lists[$before][] = $after;
		}
		else{
			$message = 'The step "'.$before.'" cannot be ordered before the step "'.$after.'".  Doing so would create a cycle in the orderings: ';
			$path = $this->dfs_path($after, $before, array());
			for($i=0; $i<count($path)-1; $i++)
				$message .= $path[$i].' < ';
			$message .= $path[count($path) - 1].'.';
			throw new Exception($message);
		}
	}
	
	public function can_come_before($before, $after){
		$before = (string) $before;
		$after = (string) $after;
		if($before == $after)
			return false;
		elseif($this->cache)
			return in_array($after, $this->cache[$before]);
		else
			return !$this->dfs($after, $before, array());
	}
	
	private function dfs($from, $to, $mark){
		if(array_key_exists($from, $this->adjacency_lists)){
			foreach($this->adjacency_lists[$from] as $neighbor){
				if($neighbor == $to)
					return true;
				if(!array_key_exists($neighbor, $mark)){
					$mark[$neighbor] = true;
					if($this->dfs($neighbor, $to, $mark))
						return true;
				}
			}
		}
		return false;
	}
	
	public function cache($story){
		$cache = array();
		foreach($story->steps as $before){
			$before = (string) $before;
			$cache[$before] = array();
			foreach($story->steps as $after)
				if($this->can_come_before($before, $after))
					$cache[$before][] = $after;
		}
		$this->cahce = $cache;
	}
	
	private function dfs_path($from, $to, $mark){
		if(array_key_exists($from, $this->adjacency_lists)){
			foreach($this->adjacency_lists[$from] as $neighbor){
				if($neighbor == $to)
					return array($from, $to);
				if(!array_key_exists($neighbor, $mark)){
					$mark[$neighbor] = true;
					$path = $this->dfs_path($neighbor, $to, $mark);
					if($path != null)
						return array_merge(array($from), $path);
				}
			}
		}
		return null;
	}
}

class CausalLink {

	public $tail;
	public $head;
	public $label;
	
	public function __construct($causal_link_xml, $story){
		// Tail step
		$this->tail = $story->get_step_by_name((string) $causal_link_xml->tail);
		// Head step
		$this->head = $story->get_step_by_name((string) $causal_link_xml->head);
		// A causal link cannot go from a non-executed step to an executed step.
		if(!$this->tail->executed && $this->head->executed)
			throw new Exception('A causal link cannot go from the non-executed step "'.$this->tail.'" to the executed step "'.$this->head.'".');
		// Label
		$this->label = new Proposition($causal_link_xml->label);
		// Make sure the tail step contains an effect equal to the label.
		$fail = true;
		foreach($this->tail->effects as $effect){
			if($this->label->equals($effect)){
				$fail = false;
				break;
			}
		}
		if($fail) throw new Exception('The step "'.$this->tail.'" does not have the effect "'.$this->label.'".');
		// Make sure the head step contains a precondition equal to the label.
		$fail = true;
		foreach($this->head->preconditions as $precondition){
			if($this->label->equals($precondition)){
				$fail = false;
				break;
			}
		}
		if($fail) throw new Exception('The step "'.$this->head.'" does not have the precondition "'.$this->label.'".');
		// Impose an ordering.
		$story->orderings->impose_ordering($this->tail, $this->head);
	}
	
	public function __toString(){
		return $this->tail.'--'.$this->label.'->'.$this->head;
	}
}

function get_threats($causal_link, $step, $story){
	$threats = array();
	// Persist steps cannot threaten causal links going to the end step.
	if($step->persist && $causal_link->head == $story->get_end_step())
		return $threats;
	// If the step can occur after the tail step but before the head step...
	if($story->orderings->can_come_before($causal_link->tail, $step) && $story->orderings->can_come_before($step, $causal_link->head)){
		// If the step has an effect that is the negation of the causal link's label...
		$neg_label = $causal_link->label->negate();
		foreach($step->effects as $effect){
			if($effect->equals($neg_label))
				$threats[] = $effect;
		}
	}
	return $threats;
}

class IntentionFrame {
	
	public $actor;
	public $goal;
	public $motivating_step;
	public $satisfying_step;
	public $steps;
	public $start_time;
	public $end_time;
	
	public function __construct($frame_xml, $story){
		global $fate;
		// Actor
		$this->actor = $story->get_actor((string) $frame_xml->actor);
		// Goal
		$this->goal = new Proposition($frame_xml->goal);
		// Motivating step
		$this->motivating_step = $story->get_step_by_name((string) $frame_xml->motivation);
		// Steps
		$this->steps = array();
		foreach($frame_xml->steps->step as $step)
			$this->steps[] = $story->get_step_by_name((string) $step);
		// Satisfying step
		$this->satisfying_step = $story->get_step_by_name((string) $frame_xml->satisfaction);
		$this->steps[] = $this->satisfying_step;		
		// Make sure the motivating step has an intention matching the actor and goal.
		$fail = true;
		foreach($this->motivating_step->intentions as $intention){
			if($this->actor == $intention->actor && $this->goal->equals($intention->goal)){
				$intention->frame = $this;
				$fail = false;
				break;
			}
		}
		if($fail) throw new Exception('The step "'.$this->motivating_step.'" does not have the intention "'.$this->goal.'".');
		// Make sure the satisfying step has an effect matching the goal.
		$fail = true;
		foreach($this->satisfying_step->effects as $effect){
			if($this->goal->equals($effect)){
				$fail = false;
				break;
			}
		}
		if($fail) throw new Exception('The step "'.$this->satisfying_step.'" does not have the effect "'.$this->goal.'".');
		// Make sure each step is intended by the actor.
		foreach($this->steps as $step){
			$fail = true;
			foreach($step->actors as $actor){
				if($this->actor == $actor){
					$step->frames[] = $this;
					$fail = false;
					break;
				}
			}
			if($fail) throw new Exception('The step "'.$step.'" does not have "'.$this->actor.'" as one of its actors.');
		}
		// Order all steps in the frame after the motivating step.
		foreach($this->steps as $step)
			$story->orderings->impose_ordering($this->motivating_step, $step);
		// Start time
		$this->start_time = $this->motivating_step->time;
		// End time
		$this->end_time = $this->start_time;
		if($this->motivating_step->executed){
			$end_time_fixed = false;
			foreach($this->steps as $step){
				if($step->executed)
					$this->end_time = $step->time;
				else{
					$in_links = $story->get_causal_links_by_head_step($step);
					foreach($in_links as $in_link){
						for($i=0; $story->steps[$i]->time < $step->time; $i++){
							if($story->steps[$i]->executed && count(get_threats($in_link, $story->steps[$i], $story)) > 0){
								$this->end_time = $story->steps[$i]->time;
								$end_time_fixed = true;
								break;
							}
						}
						if($end_time_fixed) break;
					}
					if(!$end_time_fixed)
						$this->end_time = $step->time;
				}
				if($end_time_fixed) break;
			}
		} 
	}
	
	public function get_last_executed_step(){
		$les = $this->motivating_step;
		foreach($this->steps as $step)
			if($step->executed)
				$les = $step;
		if($les->executed) return $les;
		else return null;
	}
	
	public function __toString(){
		$str = $this->actor.' intends '.$this->goal.' because of '.$this->motivating_step.' plans ';
		foreach($this->steps as $step)
			$str .= $step.' ';
		$str .= ' (starts at '.$this->start_time.'; ends at '.$this->end_time.').';
		return $str;
	}
}

class FateIntentionFrame extends IntentionFrame {

	public function __construct($story){
		global $fate;
		$this->actor = $fate;
		$this->goal = null;
		$this->motivating_step = $story->steps[0];
		$this->satisfying_step = $story->steps[count($story->steps) - 1];
		$this->steps = array();
		foreach($story->steps as $step){
			if($step->actors[0] == $fate){
				$this->steps[] = $step;
				$step->frames[] = $this;
			}
		}
		$this->start_time = $this->motivating_step->time;
		$this->end_time = $this->satisfying_step->time;
	}
}

class ConflictLink {

	public $causal_link;
	public $head_step_frame;
	public $threatening_step;
	public $threatening_step_frame;
	public $start_time;
	public $end_time;
	
	public function __construct($causal_link, $head_step_frame, $threatening_step, $threatening_step_frame){
		// The frames must be different.
		if($head_step_frame == $threatening_step_frame)
			throw new Exception('The causal link "'.$causal_link.'" is threatened by the effect "'.$causal_link->label->negate().'" of step "'.$threatening_step.'" and is not a conflict link.');
		// Either the head step or the threatening step (or both) must be non-executed.
		if($causal_link->head->executed && $threatening_step->executed)
			throw new Exception('The causal link "'.$causal_link.'" is threatened by the effect "'.$causal_link->label->negate().'" of step "'.$threatening_step.'" and is not a conflict link.');
		$this->causal_link = $causal_link;
		$this->head_step_frame = $head_step_frame;
		$this->threatening_step = $threatening_step;
		$this->threatening_step_frame = $threatening_step_frame;
		// The duration of a conflict link cannot be 0.
		if(($head_step_frame->end_time < $threatening_step_frame->start_time) || ($threatening_step_frame->end_time < $head_step_frame->start_time))
			throw new Exception('A conflict link cannot be formed from the causal link "'.$causal_link.'" and the step "'.$threatening_step.'" because the duration would be 0.');
		// Start time
		$this->start_time = max($head_step_frame->motivating_step->time, $threatening_step_frame->motivating_step->time);
		// End time
		$this->end_time = min($causal_link->head->time, $threatening_step->time, $head_step_frame->end_time, $threatening_step_frame->end_time);
	}

	public function __toString(){
		$str = $this->head_step_frame->actor.' intends "'.$this->causal_link.'" (from '.$this->head_step_frame->start_time.' to '.$this->head_step_frame->end_time.') ';
		$str .= 'but '.$this->threatening_step_frame->actor.' intends "'.$this->threatening_step.'" (from '.$this->threatening_step_frame->start_time.' to '.$this->threatening_step_frame->end_time.'). ';
		$str .= '(starts at '.$this->start_time.'; ends at '.$this->end_time.')';
		return $str;
	}
}

?>