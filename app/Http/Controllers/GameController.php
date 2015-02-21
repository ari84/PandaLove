<?php namespace PandaLove\Http\Controllers;

use Guzzle\Http\QueryAggregator\CommaAggregator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

use Onyx\Destiny\Objects\Comment;
use Onyx\Destiny\Objects\Game;
use Onyx\Destiny\Helpers\Utils\Game as GameHelper;

use PandaLove\Http\Requests;
use PandaLove\Http\Requests\AddCommentRequest;

class GameController extends Controller {

    /**
     * @var \Illuminate\Http\Request
     */
    private $request;

    public function __construct(Request $request)
    {
        parent::__construct();
        $this->request = $request;
    }

    public function getIndex()
    {
        $raids = Game::raid()->singular()->limit(4)->get();
        $flawless = Game::flawless()->singular()->limit(4)->get();
        $tuesday = Game::tuesday()->limit(4)->get();

        return view('games.index')
            ->with('raids', $raids)
            ->with('flawless', $flawless)
            ->with('tuesday', $tuesday);
    }

    public function getGame($instanceId, $all = false)
    {
        try
        {
            $game = Game::with('players.character', 'players.account', 'comments.player')
                ->where('instanceId', $instanceId)
                ->firstOrFail();

            $game->players->each(function($player)
            {
                $player->kd = $player->kdr();
            })->sortByDesc('kd');

            return view('games.game')
                ->with('game', $game)
                ->with('showAll', boolval($all));
        }
        catch (ModelNotFoundException $e)
        {
            \App::abort(404);
        }
    }

    public function getTuesday($raidTuesday)
    {
        $games = Game::with('players.character', 'players.account')->OfTuesday($raidTuesday)->get();

        if ($games->isEmpty())
        {
            \App::abort(404);
        }

        $combined = GameHelper::buildCombinedStats($games);

        return view('games.tuesday')
            ->with('raidTuesday', intval($raidTuesday))
            ->with('games', $games)
            ->with('combined', $combined);
    }

    public function getHistory($category = '')
    {
        $raids = null;

        switch ($category)
        {
            case "Raid":
                $raids = Game::raid()
                    ->singular()
                    ->with('players.account')
                    ->paginate(10);
                break;

            case "Flawless";
                $raids = Game::flawless()
                    ->singular()
                    ->with('players.account')
                    ->paginate(10);
                break;

            case "RaidTuesdays";
                $raids = Game::tuesday()
                    ->with('players.account')
                    ->paginate(10);
                break;

            default:
                \App::abort(404);
                break;
        }

        return view('games.history')
            ->with('raids', $raids);
    }

    public function postComment(AddCommentRequest $request)
    {
        $game = Game::where('instanceId', $request->get('game_id'))->first();
        $membershipId =  $this->user->account->membershipId;

        $comment = new Comment();
        $comment->comment = $request->get('message');
        $comment->membershipId = $membershipId;
        $comment->characterId = $game->findAccountViaMembershipId($membershipId, false)->characterId;
        $comment->parent_comment_id = 0;

        $game->comments()->save($comment);

        return response()->json(['flag' => true, 'url' => \URL::action('GameController@getGame', $game->instanceId)]);
    }

}
