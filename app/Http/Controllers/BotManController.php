<?php

namespace App\Http\Controllers;

use BotMan\BotMan\BotMan;
use Illuminate\Http\Request;
use App\Conversations\ExampleConversation;
use GuzzleHttp\Client;
use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;
use SlackUser;
use Validator;
use Carbon\Carbon;
use DateTime;

class BotManController extends Controller
{
    /**
     * Place your BotMan logic here.
     */
    public function handle(Request $request)
    {
        $data = $request->all();

        $botman = app('botman');

        $slackUsers = SlackUser::lists();
        $members = [];
        if (@$slackUsers->ok) {
            foreach ($slackUsers->members as $val) {
                $members[] = [
                    'id' => $val->id,
                ];
                foreach ($val->profile as $key => $value) {
                    $members[sizeof($members) - 1][$key] = $value;
                }
            }
        }
        $botman->hears('miccheck', function ($bot) {
            $bot->reply('1 and 2, testing...');
        });

        // Fallback in case of wrong command
        $botman->fallback(function ($bot) use ($data, $members) {
            $user_id = @$data['event']['user'];
            $team_id = @$data['team_id'];

            $user    = $this->findUser($members, $user_id, $team_id);

            // $bot->reply('<@'.@$user['id']."> hohoho ".@$user['email'].' kita kita hoho');

            // $command = "@timebound in 8:00AM project-id:ticket-number Description of work";
            $command = null;
            $bot_id  = null;
            if (isset($data['event']['text'])) {
                $command = $data['event']['text'];
            }
            if (isset($data['authed_users'][0])) {
                $bot_id = $data['authed_users'][0];
            }
            
            $detect = explode(' ', $command);
            
            // time in
            if (@$detect[1] == "in" && $detect[0] == "<@".$bot_id.">") {

                $info = "Here is an example of time in '@timebound in 8:00AM project-name:ticket-number Description of work'";
                
                if (sizeof($detect) < 5) {
                    $error = "Invalid format: ".$info;
                    $bot->reply($error);
                    return;
                }
                
                if (empty($detect[sizeof($detect) - 1])) {
                    $error = "Invalid format: ".$info;
                    $bot->reply($error);
                    return;
                }
                $project = explode(':', $detect[3]);
                if (sizeof($project) != 2) {
                    $error = "Invalid format: ".$info;
                    $bot->reply($error);
                    return;
                }

                $description = null;
                foreach ($detect as $key => $value) {
                    if ($key >= 4) {
                        $description .=" ".$value;
                    }
                }
                $postData = [
                    'user_email'  => @$user['email'],
                    'gesture'     => @$detect[1],
                    'time'        => @$detect[2],
                    'project_id'  => @$project[0],
                    'ticket'      => @$project[1],
                    'description' => trim(@$description),
                ];
                
                $timeHours = $postData['time'];
                $valid_time = $this->isTimeValid($timeHours);
                if (!$valid_time) {
                    $bot->reply('<@'.@$user['id']."> time input is invalid. ".$info);
                    return;
                }
                $return = [
                    '"User is not registered."',
                    '"User is already timed in."',
                    '"Time in is registered."',
                    '"User is not timed in."',
                    '"Time out is registered."',
                ];

                $client = new \GuzzleHttp\Client();
                $response = $client->request('POST', $endpoint."/api/slack/action?".http_build_query($postData));

                if ($response->getBody()->getContents() == '"User is not registered."') {
                    $bot->reply('<@'.@$user['id']."> is not registered.");
                }elseif ($response->getBody()->getContents() == '"User is already timed in."') {
                    $bot->reply('<@'.@$user['id']."> is already timed in.");
                }elseif ($response->getBody()->getContents() == '"Time in is registered."') {
                    $bot->reply('<@'.@$user['id']."> time in registered.");
                }

            }

            // time out
            if (@$detect[1] == "out" && $detect[0] == "<@".$bot_id.">") {
                
                $info = "Here is an example of time out '@timebound out 5:00PM'";
                if (sizeof($detect) < 3) {
                    $error = "Invalid format: ".$info;
                    $bot->reply($error);
                    return;
                }
                
                if (empty($detect[sizeof($detect) - 1])) {
                    $error = "Invalid format: ".$info;
                    $bot->reply($error);
                    return;
                }

                $postData = [
                    'user_email'  => @$user['email'],
                    'gesture'     => @$detect[1],
                    'time'        => @$detect[2],
                ];
                
                $timeHours = $postData['time'];
                $valid_time = $this->isTimeValid($timeHours);
                if (!$valid_time) {
                    $bot->reply('<@'.@$user['id']."> time input is invalid. ".$info);
                    return;
                }
                $client = new \GuzzleHttp\Client();
                $response = $client->request('POST', $endpoint."/api/slack/action?".http_build_query($postData));

                $return = [
                    '"User is not registered."',
                    '"User is already timed in."',
                    '"Time in is registered."',
                    '"User is not timed in."',
                    '"Time out is registered."',
                ];

                if ($response->getBody()->getContents() == '"User is not timed in."') {
                    $bot->reply('<@'.@$user['id']."> is not timed in.");
                }elseif ($response->getBody()->getContents() == '"Time out is registered."') {
                    $bot->reply('<@'.@$user['id']."> time out registered.");
                }

            }

        });

        $botman->listen();

        // $postData = [
        //     'user_email'  => 'jal.lorenzo@yahoo.com',
        //     'gesture'     => 'in',
        //     'time'        => '8:00am',
        //     'project_id'  => 'timebound',
        //     'ticket'      => 'tickets',
        //     'description' => 'descriptionasdfalskdfjalskdfjsad fosdflsajf',
        // ];
        // $endpoint = env('TIMEBOUND_URL');
        // $client = new \GuzzleHttp\Client();
        // $response = $client->request('POST', $endpoint."/api/slack/action?".http_build_query($postData));

        // dd(get_class_methods($response), $response->getReasonPhrase(),$response->getBody()->getContents());
    }

    public function findUser($member, $user_id, $team_id)
    {
        if (sizeof($member) <= 0) {
            return null;
        }
        foreach ($member as $v) {
            if ($v['id'] == $user_id && $v['team'] == $team_id) {
                return $v;
            }
        }
        return null;
    }

    public function tinker()
    {
        // abort(404);
        return view('tinker');
    }

    function isTimeValid($time) {
        return is_object(DateTime::createFromFormat('h:i a', $time));
    }

    public function startConversation(BotMan $bot)
    {
        $bot->startConversation(new ExampleConversation());
    }
}
