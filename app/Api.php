<?php namespace App;

use App\Exceptions\BinaryLaneException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Api
{
    public function account() : array
    {
        try
        {
            return Http::binarylane()
                ->get('account')
                ->throw()
                ->json('account');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch account information", $e->response, $e);
        }
    }

    public function servers(string $hostname = null) : array
    {
        try
        {
            return Http::binarylane()
                ->get('servers', ['hostname' => $hostname])
                ->throw()
                ->json('servers');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch server information", $e->response, $e);
        }
    }

    public function backup(array $server) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'serverid' => $server['id'],
                ])
                ->post('servers/{serverid}/actions', [
                    'type' => 'take_backup',
                    'backup_type' => 'temporary',
                    'replacement_strategy' => 'oldest',
                    'label' => 'API initiated backup',
                ])
                ->throw()
                ->json('action');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not initiate server backup for {$server['name']}", $e->response, $e);
        }
    }

    public function action(int $actionId) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'actionid' => $actionId,
                ])
                ->get('actions/{actionid}')
                ->throw()
                ->json('action');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch backup status {$actionId}", $e->response, $e);
        }
    }
}
