<?php
namespace App\Domain\Handlers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\ResponseEmitter;

use App\Domain\Handlers\HttpErrorHandler;

class ShutdownHandler
{
    private const FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * @var Request
     */
    private $request;

    /**
     * @var HttpErrorHandler
     */
    private $errorHandler;

    /**
     * @var bool
     */
    private $displayErrorDetails;

    /**
     * ShutdownHandler constructor.
     *
     * @param Request           $request
     * @param HttpErrorHandler  $errorHandler
     * @param bool              $displayErrorDetails
     */
    public function __construct(Request $request, HttpErrorHandler $errorHandler, bool $displayErrorDetails) {
        $this->request = $request;
        $this->errorHandler = $errorHandler;
        $this->displayErrorDetails = $displayErrorDetails;
    }

    public function __invoke()
    {
        $error = error_get_last();
        // Only fatal errors abort the request. Warnings, notices and deprecations must not replace an
        // already rendered page with a 500 (this broke every HEAD request and truncated GET responses).
        if ($error && in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
            $errorFile = $error['file'];
            $errorLine = $error['line'];
            $errorMessage = $error['message'];
            $errorType = $error['type'];
            $message = 'An error while processing your request. Please try again later.';

            if ($this->displayErrorDetails) {
                switch ($errorType) {
                    case E_USER_ERROR:
                        $message = "FATAL ERROR: {$errorMessage}. ";
                        $message .= " on line {$errorLine} in file {$errorFile}.";
                        break;

                    default:
                        $message = "ERROR: {$errorMessage}";
                        $message .= " on line {$errorLine} in file {$errorFile}.";
                        break;
                }
            }

            $exception = new HttpInternalServerErrorException($this->request, $message);
            $response = $this->errorHandler->__invoke($this->request, $exception, $this->displayErrorDetails, false, false);
            
            if (ob_get_length()) {
              ob_clean();
            }

            $responseEmitter = new ResponseEmitter();
            $responseEmitter->emit($response);
        }
    }
}