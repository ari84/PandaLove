<?php namespace Onyx\Destiny;

use Carbon\Carbon;
use GuzzleHttp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Onyx\Account;
use Onyx\Destiny\Helpers\Network\Http;
use Onyx\Destiny\Helpers\String\Hashes;
use Onyx\Destiny\Helpers\String\Text;
use Onyx\Destiny\Helpers\Utils\Gametype;
use Onyx\Destiny\Objects\Character;
use Onyx\Destiny\Objects\Game;
use Onyx\Destiny\Objects\GamePlayer;
use Onyx\Destiny\Objects\PVP;
use PandaLove\Commands\UpdateGamertag;

class Client extends Http {

    //---------------------------------------------------------------------------------
    // Public Methods
    //---------------------------------------------------------------------------------

    /**
     * @param $instanceId
     * @param $type (Raid, Flawless, PVP)
     * @return mixed
     * @throws GameNotFoundException
     * @throws Helpers\Network\BungieOfflineException
     */
    public function fetchGameByInstanceId($instanceId, $type = null)
    {
        $url = sprintf(Constants::$postGameCarnageReport, $instanceId);

        try
        {
            $game = Game::where('instanceId', $instanceId)->firstOrFail();
            return $game;
        }
        catch (ModelNotFoundException $e)
        {
            $json = $this->getJson($url);

            if (isset($json['Response']['data']['activityDetails']))
            {
                $this->createGame($url, $json, $type);
            }
            else
            {
                throw new GameNotFoundException();
            }
        }
    }

    /**
     * @param string $instanceId
     * @param string $type
     * @param int $raidTuesday
     * @throws GameNotFoundException
     */
    public function updateTypeOfGame($instanceId, $type, $raidTuesday = null)
    {
        try
        {
            $game = Game::where('instanceId', $instanceId)->firstOrFail();
            $game->type = $type;

            if ($raidTuesday != null && $game->type == "Raid")
            {
                $game->raidTuesday = intval($raidTuesday);
            }

            if ($raidTuesday != null && $game->type == "ToO")
            {
                // Trials Of Osiris
                $game->passageId = intval($raidTuesday);
            }

            $game->save();

            return $game;
        }
        catch (ModelNotFoundException $e)
        {
            throw new GameNotFoundException();
        }
    }

    /**
     * @param $instanceId
     * @return bool
     * @throws Helpers\Network\BungieOfflineException
     */
    public function updateGame($instanceId)
    {
        try
        {
            $game = Game::where('instanceId', $instanceId)->firstOrFail();
            $url = sprintf(Constants::$postGameCarnageReport, $instanceId);
            $json = $this->getJson($url);

            $this->updateGameForNewField($json, $game);
        }
        catch (ModelNotFoundException $e)
        {
            return false;
        }
    }

    /**
     * @param $platform
     * @param $gamertag
     * @return \Onyx\Account
     * @throws Helpers\Network\BungieOfflineException
     * @throws PlayerNotFoundException
     */
    public function fetchAccountByGamertag($platform, $gamertag)
    {
        $platform = intval($platform);
        $url = sprintf(Constants::$searchDestinyPlayer, $platform, $gamertag);

        $account = $this->checkCacheForGamertag($gamertag);

        // @todo check if destiny_membershipId is NULL, if so. Pull JSON and update fields
        if ($account instanceof Account)
        {
            return $account;
        }

        $json = $this->getJson($url);

        if (isset($json['Response'][0]['membershipId']))
        {
            try
            {
              return Account::firstOrCreate([
                    'gamertag' => $json['Response'][0]['displayName'],
                    'accountType' => $json['Response'][0]['membershipType'],
                    'destiny_membershipId' => $json['Response'][0]['membershipId']
                ]);
            }
            catch (QueryException $e)
            {
                // Assuming this character already exists, but has had a name change
                $char = Character::where('membershipId', $json['Response'][0]['membershipId'])->first();
                $account = $char->account;

                if ($account instanceof Account)
                {
                    $account->gamertag = $json['Response'][0]['displayName'];
                    $account->save();

                    return $account;
                }

                return Account::firstOrCreate([
                    'gamertag' => $json['Response'][0]['displayName'],
                    'accountType' => $json['Response'][0]['membershipType'],
                    'destiny_membershipId' => $json['Response'][0]['membershipId']
                ]);
            }
        }
        else
        {
            throw new PlayerNotFoundException();
        }
    }

    /**
     * @param /Onyx/Destiny/Objects/Data $account
     * @return array
     * @throws Helpers\Network\BungieOfflineException
     */
    public function fetchAccountData($account)
    {
        $url = sprintf(Constants::$platformDestiny, $account->accountType, $account->destiny_membershipId);

        $data = $account->destiny;
        $json = $this->getJson($url);

        if (isset($json['Response']['data']['clanName']))
        {
            $data->clanName = $json['Response']['data']['clanName'];

            if (isset($json['Response']['data']['clanTag']))
            {
                $data->clanTag = $json['Response']['data']['clanTag'];
            }
        }

        if (isset($json['Response']['data']['inventory']['currencies']))
        {
            $data->glimmer = $json['Response']['data']['inventory']['currencies'][0]['value'];
            $data->legendary_marks = $json['Response']['data']['inventory']['currencies'][1]['value'];
        }

        $data->grimoire = $json['Response']['data']['grimoireScore'];

        $charactersCount = count($data->characters);

        // characters
        $chars = [];
        for ($i = 0; $i <= 3; $i++)
        {
            if (isset($json['Response']['data']['characters'][$i]))
            {
                $chars[$i] = $this->updateOrAddCharacter($url, $json['Response']['data']['characters'][$i]);
                $pair = "character_" . ($i + 1);
                $data->$pair = $json['Response']['data']['characters'][$i]['characterBase']['characterId'];
            }
        }

        if ($charactersCount > 3)
        {
            // we have too many characters due to deletions, delete any that don't have the ID anymore
            $characters = $data->characters;
            $allowed = $data->characterIds();

            foreach ($characters as $char)
            {
                if (! in_array($char->characterId, $allowed))
                {
                    $char->delete();
                }
            }
        }

        // check for inactivity
        $this->tabulateActivity($data, $chars);

        $data->save();

        return $account;
    }

    /**
     * @param /Onyx/Account $account
     * @return array|null
     */
    public function getBungieProfile($account)
    {
        $url = sprintf(Constants::$getBungieAccount, $account->destiny_membershipId, $account->accountType);

        $json = $this->getJson($url);

        return isset($json['Response']['bungieNetUser']) ? $json['Response']['bungieNetUser'] : null;
    }

    /**
     * @return bool
     * @throws Helpers\Network\BungieOfflineException
     */
    public function getXurData()
    {
        $key = 'xur';

        if (Cache::has($key))
        {
            return Cache::get($key);
        }
        else
        {
            $url = Constants::$xurData;
            $json = $this->getJson($url);

            if (! isset($json['Response']['data']))
            {
                return false; // no xur data
            }
            else
            {
                $translator = new Hashes();
                $translator->setUrl($url);

                $items = '<strong>Xur Items</strong><br/><br />';

                foreach($json['Response']['data']['saleItemCategories'] as $category)
                {
                    if ($category['categoryTitle'] == "Exotic Gear")
                    {
                        foreach($category['saleItems'] as $item)
                        {
                            if (isset($item['item']['stats']) && count($item['item']['stats']) > 0)
                            {
                                $items .= "<strong>" . $translator->map($item['item']['itemHash'], true) . '</strong><br />';
                                foreach ($item['item']['stats'] as $stat)
                                {
                                    if ($stat['value'] != 0)
                                    {
                                        $items .= '  -->' . $translator->map($stat['statHash'], true) . ": " . number_format($stat['value'])  . "<br />";
                                    }

                                }
                                $items .= '<br />';
                            }
                        }
                    }
                }

                Cache::put($key, $items, 120);
                return $items;
            }
        }
    }

    //---------------------------------------------------------------------------------
    // Private Methods
    //---------------------------------------------------------------------------------

    private function updateGameForNewField($data, $game)
    {
        $game->version = config('app.version', 1);

        if (isset($data['Response']['data']['activityDetails']['mode']) &&
            Gametype::isPVP($data['Response']['data']['activityDetails']['mode']))
        {
            $pvp = PVP::where('instanceId', $game->instanceId)->first();

            $entries = $data['Response']['data']['entries'];

            // delete old game-players
            GamePlayer::where('game_id', $game->instanceId)->delete();

            foreach($entries as $entry)
            {
                $this->gamePlayerSetup($data, $entry, $game, $pvp, false);
            }
        }

        $game->save();
    }

    /**
     * @param string $url
     * @param array $data
     * @param string $type
     */
    private function createGame($url, $data, $type)
    {
        $entries = $data['Response']['data']['entries'];

        $game = new Game();
        $game->setTranslatorUrl($url);

        $game->instanceId = $data['Response']['data']['activityDetails']['instanceId'];
        $game->referenceId = $data['Response']['data']['activityDetails']['referenceId'];
        $game->version = config('app.version', 1);

        if (isset($data['Response']['data']['activityDetails']['mode']) &&
            Gametype::isPVP($data['Response']['data']['activityDetails']['mode']))
        {
            // delete old PVP-Games
            PVP::where('instanceId', $game->instanceId)->delete();

            // create new one
            $pvp = new PVP();
            $pvp->instanceId = $game->instanceId;
            $pvp->gametype = $data['Response']['data']['activityDetails']['mode'];

            foreach($data['Response']['data']['teams'] as $team)
            {
                if ($team['standing']['basic']['value'] == 0) // 0 = victory
                {
                    $pvp->winnerPts = $team['score']['basic']['value'];
                    $pvp->winnerId = $team['teamId'];
                }
                elseif ($team['standing']['basic']['value'] == 1) // 1 = defeat
                {
                    $pvp->loserPts = $team['score']['basic']['value'];
                    $pvp->loserId = $team['teamId'];
                }
                else
                {
                    Log::warning('Unknown Team');
                }
            }

            $pvp->save();
        }

        // delete old game-players
        GamePlayer::where('game_id', $game->instanceId)->delete();

        $game->type = $type;
        $game->occurredAt = $data['Response']['data']['period'];

        $time = [];
        foreach($entries as $entry)
        {
            $time[] = $this->gamePlayerSetup($data, $entry, $game, isset($pvp) ? $pvp : null);
        }

        // get highest $duration (MODE)
        if (is_array($time))
        {
            $time = array_filter($time);
            $game->timeTookInSeconds = Text::array_mode($time);
        }
        $game->save();
    }

    /**
     * @param $entry
     * @param $game
     * @param $pvp
     * @param $regular
     */
    private function gamePlayerSetup($data, $entry, $game, $pvp, $regular = true)
    {
        $player = new GamePlayer();
        $player->game_id = $game->instanceId;
        $player->membershipId = $entry['player']['destinyUserInfo']['membershipId'];
        $player->account_id = Account::getAccountIdViaDestiny($player->membershipId);

        // check if we have player
        if ($this->checkCacheForGamertag($entry['player']['destinyUserInfo']['displayName']) == false && $regular)
        {
            Bus::dispatch(new UpdateGamertag($entry['player']['destinyUserInfo']['displayName'],
                $entry['player']['destinyUserInfo']['membershipType']));

            if ($player->account_id == null)
            {
                // we are re-running this, because an Account doesnt exist yet so the previous check is NULL
                // this is technically a bug-fix for a RACE condition, where we cant create a Player
                // without an AccountId which doesn't exist till after the Event is dispatched.
                $player->account_id = Account::getAccountIdViaDestiny($player->membershipId);
            }
        }

        $player->characterId = $entry['characterId'];
        $player->level = $entry['player']['characterLevel'];
        $player->class = $entry['player']['characterClass'];
        $player->emblem = $entry['player']['destinyUserInfo']['iconPath'];

        $player->assists = $entry['values']['assists']['basic']['value'];
        $player->deaths = $entry['values']['deaths']['basic']['value'];
        $player->kills = $entry['values']['kills']['basic']['value'];
        $player->completed = boolval($entry['values']['completed']['basic']['value']);

        // PVP games don't seem to have secondsPlayed or averageLifespan
        if (isset($entry['values']['secondsPlayed']['basic']['value']))
        {
            $player->secondsPlayed = $entry['values']['secondsPlayed']['basic']['value'];
        }

        if (isset($entry['extended']['values']['averageLifespan']['basic']['value']))
        {
            $player->averageLifespan = $entry['extended']['values']['averageLifespan']['basic']['value'];
        }

        if (isset($entry['values']['score']['basic']['value']))
        {
            $player->score = $entry['values']['score']['basic']['value'];
        }

        if (isset($entry['values']['standing']['basic']['values']))
        {
            $player->standing = $entry['values']['standing']['basic']['value'];
        }

        // Check for team or rumble
        if (isset($entry['values']['team']['basic']['value']))
        {
            $player->team = $entry['values']['team']['basic']['value'];
        }

        // Check for revives given/received
        if (isset($entry['extended']['values']['resurrectionsPerformed']['basic']['value']))
        {
            $player->revives_given = $entry['extended']['values']['resurrectionsPerformed']['basic']['value'];
        }

        if (isset($entry['extended']['values']['resurrectionsReceived']['basic']['value']))
        {
            $player->revives_taken = $entry['extended']['values']['resurrectionsReceived']['basic']['value'];
        }

        if (isset($entry['extended']['values']['medalsActivityCompleteVictoryMercy']['basic']['value']))
        {
            $game->mercy = true;
            $game->save();
        }

        // Don't save if 0/0
        if ($player->score == 0 && $player->deaths == 0 && $player->kills == 0)
        {
            return;
        }
        else
        {
            $player->save();

            $duration = $entry['values']['activityDurationSeconds']['basic']['value'];

            if (isset($data['Response']['data']['activityDetails']['mode']) &&
                Gametype::isPVP($data['Response']['data']['activityDetails']['mode']))
            {
                // We need to figure out which "team" is PandaLove via checking the players
                if ($player->account->isPandaLove())
                {
                    if ($entry['standing'] == 0) // Victory
                    {
                        $pvp->pandaId = $pvp->winnerId;
                    }
                    else
                    {
                        $pvp->pandaId = $pvp->loserId;
                    }

                    $pvp->save();
                }
            }
        }

        return isset($duration) ? $duration : null;
    }

    /**
     * @param string $url
     * @param array $data
     * @return bool
     */
    private function updateOrAddCharacter($url, $data)
    {
        $activity = false;
        $charBase = $data['characterBase'];

        $character = Character::where('characterId', $charBase['characterId'])->first();

        if ( ! $character instanceof Character)
        {
            $character = new Character();
            $character->membershipId = $charBase['membershipId'];
            $character->characterId = $charBase['characterId'];
        }
        else
        {
            $activity = $this->checkForActivity($character, $charBase['minutesPlayedTotal']);
        }

        $character->setTranslatorUrl($url);

        $character->realLevel = $data['levelProgression']['level']; // deprecated as of TTK
        $character->next_level_exp = $data['levelProgression']['nextLevelAt'];
        $character->progress_exp = $data['levelProgression']['progressToNextLevel'];

        $character->last_played = new Carbon($charBase['dateLastPlayed']);
        $character->minutes_played = $charBase['minutesPlayedTotal'];
        $character->minutes_played_last_session = $charBase['minutesPlayedThisSession'];
        $character->level = $data['characterLevel'];
        $character->race = $charBase['raceHash'];
        $character->gender = $charBase['genderHash'];
        $character->class = $charBase['classHash'];
        $character->defense = $charBase['stats']['STAT_DEFENSE']['value'];
        $character->intellect = $charBase['stats']['STAT_INTELLECT']['value'];
        $character->discipline = $charBase['stats']['STAT_DISCIPLINE']['value'];
        $character->strength = $charBase['stats']['STAT_STRENGTH']['value'];

        if (isset($charBase['stats']['STAT_LIGHT']))
        {
            $character->light = $charBase['stats']['STAT_LIGHT']['value'];

            // apply highest light they've earned on this char.
            if (isset($character->highest_light))
            {
                $character->highest_light = max($character->light, $character->highest_light);
            }
        }
        else
        {
            // under lvl20, no LIGHT
            $character->light = 0;
        }

        $character->subclass = $charBase['peerView']['equipment'][0]['itemHash'];
        $character->helmet = $charBase['peerView']['equipment'][1]['itemHash'];
        $character->arms = $charBase['peerView']['equipment'][2]['itemHash'];
        $character->chest = $charBase['peerView']['equipment'][3]['itemHash'];
        $character->boots = $charBase['peerView']['equipment'][4]['itemHash'];
        $character->class_item = $charBase['peerView']['equipment'][5]['itemHash'];

        $character->primary = $charBase['peerView']['equipment'][6]['itemHash'];
        $character->secondary = $charBase['peerView']['equipment'][7]['itemHash'];
        $character->heavy = $charBase['peerView']['equipment'][8]['itemHash'];
        $character->ship = $charBase['peerView']['equipment'][9]['itemHash'];

        // ugly shit checking if items exist before using
        if (isset($charBase['peerView']['equipment'][10]['itemHash']))
        {
            $character->sparrow = $charBase['peerView']['equipment'][10]['itemHash'];
        }

        if (isset($charBase['peerView']['equipment'][11]['itemHash']))
        {
            $character->ghost = $charBase['peerView']['equipment'][11]['itemHash'];
        }

        if (isset($charBase['peerView']['equipment'][12]['itemHash']))
        {
            $character->background = $charBase['peerView']['equipment'][12]['itemHash'];
        }

        if (isset($charBase['peerView']['equipment'][13]['itemHash']))
        {
            $character->shader = $charBase['peerView']['equipment'][13]['itemHash'];
        }

        if (isset($charBase['peerView']['equipment'][14]['itemHash']))
        {
            $character->emote = $charBase['peerView']['equipment'][14]['itemHash'];
        }

        if (isset($charBase['peerView']['equipment'][15]['itemHash']))
        {
            $character->artifact = $charBase['peerView']['equipment'][15]['itemHash'];
        }

        $character->emblem = $data['emblemHash'];
        $character->save();
        return $activity;
    }

    /**
     * @param $gamertag
     * @return \Onyx\Account|void
     */
    private function checkCacheForGamertag($gamertag)
    {
        $account = Account::where('seo', Text::seoGamertag($gamertag))->first();

        if ($account instanceof Account)
        {
            return $account;
        }

        return false;
    }

    /**
     * @param \Onyx\Destiny\Objects\Data $destiny
     * @param array $chars
     */
    private function tabulateActivity($destiny, $chars)
    {
        $reset = false;

        foreach($chars as $key => $value)
        {
            if ($value)
            {
                $reset = true;
            }
        }

        if ($reset)
        {
            $destiny->inactiveCounter = 0;
            $destiny->save();
        }
        else
        {
            $destiny->inactiveCounter++;
            $destiny->save();
        }
    }

    /**
     * @param \Onyx\Destiny\Objects\Character $char
     * @return bool
     */
    private function checkForActivity($char, $new_minutes)
    {
        $minutes_old = $char->getMinutesPlayedRaw();

        if ($minutes_old != $new_minutes)
        {
            return true;
        }

        return false;
    }
}

class PlayerNotFoundException extends \Exception {};
class GameNotFoundException extends \Exception {};