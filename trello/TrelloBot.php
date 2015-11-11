<?
// fallback username to use when a card has no members
define('ADMIN_USERNAME', '');

// trello API authentication
define('TRELLO_ID', '');
define('TRELLO_API_KEY', '');
define('TRELLO_TOKEN', '');

// periods of time for later comparison
define('ONE_DAY', 86400); // 60 * 60 * 24 = one day in seconds
define('ONE_WEEK', 604800); // 60 * 60 * 24 * 7 = one week in seconds
define('ONE_MONTH', 2592000); // 60 * 60 * 24 * 30 = one month in seconds

use Trello\Client;

class TrelloBot {

	private $client, $board_ids, $list_ids;
	private $cards_affected;

	public function __construct() {
		$this->client = new Client();
		$this->client->authenticate(TRELLO_API_KEY, TRELLO_TOKEN, Client::AUTH_URL_CLIENT_ID);

		$this->board_ids = [
			'current_development' => $this->_getBoardIdByName('Mocavo - Current Development'),
			'in_production' => $this->_getBoardIdByName('In Production'),
		];

		$this->list_ids = [
			'done' => $this->_findListWithNameOnBoard('Done', $this->board_ids['current_development']),
			'next_up' => $this->_findListWithNameOnBoard('Next Up', $this->board_ids['current_development']),
			'in_progress' => $this->_findListWithNameOnBoard('In Progress', $this->board_ids['current_development']),
			'live_last_two_weeks' => $this->_findListWithNameOnBoard('Live (Last Two Weeks)', $this->board_ids['current_development']),
			'icebox' => $this->_findListWithNameOnBoard('Icebox', $this->board_ids['current_development']),
		];

		$this->cards_affected = 0;
	}

	/*
	 * Run all routine tasks that should be included in daily cron.
	 */
	public function index() {
		$this->askForStatusOnInProgressCards();
		$this->retrieveCardsFromIcebox();
		$this->moveDoneCardsToLiveInLastTwoWeeks();
		$this->moveOldCardsToInProductionBoard();
		echo "[{$this->cards_affected}] Trello Bot finished.\n";
	}

	/*
	 * Examine cards in the "In Progress" list. If it hasn't been touched in over
	 * a week or it has a due date in the past, @mention all members of the card
	 * asking for a status update.
	 */
	public function askForStatusOnInProgressCards() {
		$affected_cards = 0;
		$cards_that_should_be_active = $this->client->api('list')->cards()->filter($this->list_ids['in_progress']);

		foreach ($cards_that_should_be_active as $card) {
			$last_modified = strtotime($card['dateLastActivity']);
			$card_staleness = time() - $last_modified;

			if (strlen($card['due'])) {
				$due_date = strtotime($card['due']);
				$time_until_due = time() - $due_date;
			} else {
				$time_until_due = -1;
			}

			$card_needs_attention = ($card['closed'] == false) && ( ($card_staleness > ONE_WEEK) || ($time_until_due > 0) );

			if ($card_needs_attention) {
				$commentContent = 'Do you have any updates? Any blockers?';
				$this->_notifyCardMembers($card, $commentContent);

				$affected_cards++;
			}
		}

		echo "[$affected_cards] Asked for updates on stale In Progress cards.\n";
		$this->cards_affected += $affected_cards;
	}

	/*
	 * Examine cards in the "Icebox" list. If it has a due date which is in the
	 * past, move the card to "Next Up", remove the due date, and mention all
	 * of the card's members.
	 */
	public function retrieveCardsFromIcebox() {
		$affected_cards = 0;
		$icebox_cards = $this->client->api('list')->cards()->filter($this->list_ids['icebox']);

		foreach ($icebox_cards as $card) {
			if (strlen($card['due'])) {
				$due_date = strtotime($card['due']);
				$time_until_due = time() - $due_date;

				// if the due date is in the past, bring the card into Next Up, notify members, and remove the due date
				if ($time_until_due > 0) {
					$commentContent = 'This card was waiting in the Icebox and is now ready to be worked on.';
					$this->_notifyCardMembers($card, $commentContent);
					$this->client->api('card')->setList($card['id'], $this->list_ids['next_up']);
					$this->client->api('card')->setDueDate($card['id'], null); // remove due date

					$affected_cards++;
				}
			}
		}

		echo "[$affected_cards] Moved overdue Icebox cards to Next Up.\n";
		$this->cards_affected += $affected_cards;
	}

	/* Examine cards in the "Done" list. If it hasn't been touched today or
	 * it has been archived, move it to the "Live (Last Two Weeks)" column on
	 * the "Current Development" board.
	 */
	public function moveDoneCardsToLiveInLastTwoWeeks() {
		$affected_cards = 0;
		$done_cards = $this->client->api('list')->cards()->filter($this->list_ids['done']);

		foreach ($done_cards as $card) {
			$last_modified = strtotime($card['dateLastActivity']);
			$card_is_done = ($card['closed'] == true) || (date('j', $last_modified) != date('j'));

			if ($card_is_done) {
				$this->client->api('card')->setClosed($card['id'], false);
				$this->client->api('card')->setList($card['id'], $this->list_ids['live_last_two_weeks']);

				$affected_cards++;
			}
		}

		echo "[$affected_cards] Moved old Done cards to Live (Last Two Weeks).\n";
		$this->cards_affected += $affected_cards;
	}

	/*
	 * Examine cards in the "Live (Last Two Weeks)" list. If they haven't been
	 * touched in the last two weeks, move them to the e.g. "Live Q4 2015" list
	 * on the "In Production" board.
	 */
	public function moveOldCardsToInProductionBoard() {
		$affected_cards = 0;
		$live_last_two_weeks_cards = $this->client->api('list')->cards()->filter($this->list_ids['live_last_two_weeks']);

		foreach ($live_last_two_weeks_cards as $card) {
			$last_modified = strtotime($card['dateLastActivity']);
			$card_staleness = time() - $last_modified;

			// move cards which haven't been touched in over two weeks
			if ($card_staleness > (ONE_WEEK * 2)) {
				$quarter_list_id = $this->_findOrCreateListWithNameOnBoard($this->_getCurrentQuarterTitle($last_modified), $this->board_ids['in_production']);

				$this->client->api('card')->setBoard($card['id'], $this->board_ids['in_production']);
				$this->client->api('card')->setList($card['id'], $quarter_list_id);

				$affected_cards++;
			}
		}

		echo "[$affected_cards] Moved old Live (Last Two Weeks) cards to In Production board.\n";
		$this->cards_affected += $affected_cards;
	}

	private function _notifyCardMembers($card, $commentContent) {
		$message = ' ';

		foreach ($card['idMembers'] as $memberID) {
			$member = $this->client->api('member')->show($memberID);
			$message .= "@{$member['username']} ";
		}

		// if this card has nobody watching it, @mention the board admin
		if (count($card['idMembers']) === 0) {
			$message .= '@' . ADMIN_USERNAME . ' ';
		}

		$message .= $commentContent;

		$this->client->api('card')->actions()->addComment($card['id'], $message);

		echo "> Posted comment on card {$card['name']} ({$card['id']})\n";
	}

	private function _getCurrentQuarterTitle($time = 0) {
		if ($time > 0) {
			$month = date('m', $time);
			$year = date('Y', $time);
		} else {
			$month = date('m');
			$year = date('Y');
		}

		$quarter = 0;

		if ($month <= 3) {
			$quarter = 1;
		} elseif ($month <= 6) {
			$quarter = 2;
		} elseif ($month <= 9) {
			$quarter = 3;
		} else {
			$quarter = 4;
		}

		return "Live Q{$quarter} {$year}";
	}

	private function _getBoardIdByName($board_name) {
		$board_list = $this->client->api('member')->boards()->all(TRELLO_ID);

		foreach ($board_list as $board) {
			if ($board['name'] == $board_name) {
				return $board['id'];
			}
		}

		return false;
	}

	private function _findListWithNameOnBoard($list_name, $board_id) {
		$board_lists = $this->client->api('board')->lists()->filter($board_id, 'open');

		foreach ($board_lists as $list) {
			if ($list['name'] == $list_name) {
				return $list['id'];
			}
		}

		return false;
	}

	private function _findOrCreateListWithNameOnBoard($list_name, $board_id) {
		$list_id = $this->_findListWithNameOnBoard($list_name, $board_id);
		if ($list_id === false) {
			$this->client->api('board')->lists()->create($board_id, ['name' => $list_name, 'pos' => 2]);
			sleep(2); // wait for Trello to catch up before we try to use this list
		} else {
			return $list_id;
		}
	}

}
