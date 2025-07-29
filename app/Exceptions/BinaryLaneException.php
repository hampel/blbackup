<?php namespace App\Exceptions;

use Illuminate\Http\Client\Response;

class BinaryLaneException extends \Exception
{
    public function __construct(string $action, Response $response, \Exception $previous = null)
    {
        $message = "{$action} [{$response->status()}]: {$response->reason()}";

        parent::__construct($message, $response->status(), $previous);
    }
}
