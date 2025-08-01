<?php namespace App;

use App\Exceptions\BinaryLaneException;
use Illuminate\Http\Client\RequestException;
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

    public function server(int $serverId) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'server_id' => $serverId,
                ])
                ->get('servers/{server_id}')
                ->throw()
                ->json('server');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch server information {$serverId}", $e->response, $e);
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
            throw new BinaryLaneException("Could not fetch server information for {$hostname}", $e->response, $e);
        }
    }

    public function createBackup(array $server) : array
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

    public function backups(array $server) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'server_id' => $server['id'],
                ])
                ->get('servers/{server_id}/backups')
                ->throw()
                ->json('backups');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch list of backups for server {$server['name']}", $e->response, $e);
        }
    }

    public function images() : array
    {
        try
        {
            return Http::binarylane()
                ->get('images')
                ->throw()
                ->json('images');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch list of images", $e->response, $e);
        }
    }

    public function image(int $imageId) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'image_id' => $imageId,
                ])
                ->get('images/{image_id}')
                ->throw()
                ->json('image');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch image {$imageId}", $e->response, $e);
        }
    }

    public function link(int $imageId) : array
    {
        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'image_id' => $imageId,
                ])
                ->get('images/{image_id}/download')
                ->throw()
                ->json('link');
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not fetch links for image {$imageId}", $e->response, $e);
        }
    }

    public function download(string $url, string $path, callable $progress)
    {
        try
        {
            return Http::sink($path)
                ->withOptions(['progress' => $progress])
                ->timeout(config('binarylane.timeout'))
                ->get($url)
                ->throw();
        }
        catch (RequestException $e)
        {
            throw new BinaryLaneException("Could not download image [{$url}]", $e->response, $e);
        }
    }


}
