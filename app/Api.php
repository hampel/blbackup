<?php namespace App;

use App\Exceptions\BinaryLaneException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Api
{
    public function account() : array
    {
        Log::debug('api::account');

        try
        {
            return Http::binarylane()
                ->get('account')
                ->throw()
                ->json('account');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch account information", $e->response, $e);
        }
    }

    public function server(int $server_id) : array
    {
        Log::debug('api::account', compact(['server_id']));

        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'server_id' => $server_id,
                ])
                ->get('servers/{server_id}')
                ->throw()
                ->json('server');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch server information {$server_id}", $e->response, $e);
        }
    }

    public function servers(string $hostname = null) : array
    {
        Log::debug('api::servers', compact(['hostname']));

        try
        {
            return Http::binarylane()
                ->get('servers', ['hostname' => $hostname])
                ->throw()
                ->json('servers');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch server information for {$hostname}", $e->response, $e);
        }
    }

    public function createBackup(array $server) : array
    {
        Log::debug('api::createBackup', $this->serverContext($server));

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
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not initiate server backup for {$server['name']}", $e->response, $e);
        }
    }

    public function action(int $action_id) : array
    {
        Log::debug('api::action', compact(['action_id']));

        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'actionid' => $action_id,
                ])
                ->get('actions/{actionid}')
                ->throw()
                ->json('action');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch backup status {$action_id}", $e->response, $e);
        }
    }

    public function backups(array $server) : array
    {
        Log::debug('api::backups', $this->serverContext($server));

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
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch list of backups for server {$server['name']}", $e->response, $e);
        }
    }

    public function images() : array
    {
        Log::debug('api::images');

        try
        {
            return Http::binarylane()
                ->get('images')
                ->throw()
                ->json('images');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch list of images", $e->response, $e);
        }
    }

    public function image(int $image_id) : array
    {
        Log::debug('api::image', compact(['image_id']));

        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'image_id' => $image_id,
                ])
                ->get('images/{image_id}')
                ->throw()
                ->json('image');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch image {$image_id}", $e->response, $e);
        }
    }

    public function link(int $image_id) : array
    {
        Log::debug('api::link', compact(['image_id']));

        try
        {
            return Http::binarylane()
                ->withUrlParameters([
                    'image_id' => $image_id,
                ])
                ->get('images/{image_id}/download')
                ->throw()
                ->json('link');
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not fetch links for image {$image_id}", $e->response, $e);
        }
    }

    public function download(string $url, string $path, callable $progress)
    {
        Log::debug('api::download', compact(['url', 'path']));

        try
        {
            return Http::sink($path)
                ->withOptions(['progress' => $progress])
                ->timeout(config('binarylane.timeout'))
                ->get($url)
                ->throw();
        }
        catch (HttpClientException $e)
        {
            throw new BinaryLaneException("Could not download image [{$url}]", $e->response, $e);
        }
    }

    protected function serverContext(array $server) : array
    {
        return [
            'server_id' => $server['id'],
            'name' => $server['name'],
            'disk' => $server['disk'],
        ];
    }
}
