<?php

$dir = dirname(__FILE__);
$baseDir = dirname($dir);

require_once($dir . '/constants.php');

// ---------------------------

// If present, load linker configuration from linker.json and linker.d.
require_once("$baseDir/classes/CCR/Json.php");
require_once("$baseDir/classes/Xdmod/Config.php");
$config = Xdmod\Config::factory();
try {
    $linkerConfig = $config['linker'];
} catch (Exception $e) {
    $configDir = $config->getConfigDirPath();
    echo "Could not find valid \"linker.json\" or \"linker.d\" files in \"$configDir\".\n";
    echo "Please set up valid linker configuration files and try again.\n";
    exit(1);
}

// Load configured autoloaders.
if (isset($linkerConfig['autoloaders'])) {
    foreach ($linkerConfig['autoloaders'] as $autoloaderPath) {
        require_once("$baseDir/$autoloaderPath");
    }
}

// Update PHP's include path to include certain XDMoD directories.
if (isset($linkerConfig['include_dirs'])) {
    $include_path  = ini_get('include_path');
    foreach ($linkerConfig['include_dirs'] as $includeDirPath) {
        $include_path .= ":$baseDir/$includeDirPath";
    }

    ini_alter('include_path', $include_path);
}

// Register a custom autoloader for XDMoD components.
function xdmodAutoload($className)
{
    $pathList = explode(":", ini_get('include_path'));

   // if class does not have a namespace
    if(strpos($className, '\\') === false) {
        $includeFile = $className.".php";
        foreach ($pathList as $path) {
            if (is_readable("$path/$includeFile")) {
                require_once("$path/$includeFile");
                break;
            }
        }
    } else {
        // convert namespace to full file path
        $class = dirname(__FILE__) . '/../classes/'
         . str_replace('\\', '/', $className) . '.php';
        if (is_readable("$class")) {
            require_once($class);
        }
    }
}

spl_autoload_register('xdmodAutoload');

// Libraries ---------------------------

require_once($baseDir . '/libraries/utilities.php');

$libraries = scandir($baseDir . '/libraries');

foreach ($libraries as $library) {
    $file = "$baseDir/libraries/$library";
    if (is_dir($file)) {
        continue;
    }
    require_once($file);
}

class HttpCodeMessages
{
    // HTTP 1.1 messages from: http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html

    public static $messages = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        // 306 Unused
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported'
    );
};

// Global Exception Handler (Uncaught exceptions will be logged) ------------------------------

/**
 * Log an uncaught exception and convert it into output for a handler.
 *
 * @param  Exception $exception    The exception to log and convert.
 * @return array                   An associative array containing:
 *                                    * content: The content to output.
 *                                    * isServerContext: Indicates if this is
 *                                      running in a server context.
 *                                    * headers: The headers to output.
 *                                    * httpCode: The HTTP status code to use.
 */
function handle_uncaught_exception($exception)
{
    $logfile = LOG_DIR . "/" . xd_utilities\getConfiguration('general', 'exceptions_logfile');

    $logConf = array('mode' => 0644);
    $logger = \Log::factory('file', $logfile, 'exception', $logConf);

    $logger->log('Exception Code: '.$exception->getCode(), PEAR_LOG_ERR);
    $logger->log('Message: '.$exception->getMessage(), PEAR_LOG_ERR);
    $logger->log('Origin: '.$exception->getFile().' (line '.$exception->getLine().')', PEAR_LOG_INFO);

    $stringTrace = (get_class($exception) == 'UniqueException') ? $exception->getVerboseTrace() : $exception->getTraceAsString();

    $logger->log("Trace:\n".$stringTrace."\n-------------------------------------------------------", PEAR_LOG_INFO);

   // If working in a server context, build headers to output.
    $httpCode = 500;
    $headers = array();
    $isServerContext = isset($_SERVER['SERVER_PROTOCOL']);
    if ($isServerContext) {
        $uncheckedExceptionHttpCode = null;
        if ($exception instanceof XDException) {
            $uncheckedExceptionHttpCode = $exception->httpCode;
            $headers = $exception->headers;
        } elseif ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $uncheckedExceptionHttpCode = $exception->getStatusCode();
            $headers = $exception->getHeaders();
        }

        if ($uncheckedExceptionHttpCode !== null) {
            if (array_key_exists($uncheckedExceptionHttpCode, HttpCodeMessages::$messages)) {
                $httpCode = $uncheckedExceptionHttpCode;
            }
        }
    }

    $exceptionData = xd_response\buildError($exception);
    $content = json_encode($exceptionData);
    if ($isServerContext) {
        $headers['Content-Type'] = 'application/json';
    }

    return array(
      'content' => $content,
      'isServerContext' => $isServerContext,
      'headers' => $headers,
      'httpCode' => $httpCode,
    );
} // handle_uncaught_exception

function global_uncaught_exception_handler($exception)
{
   // Perform logging and output building for the exception.
    $exceptionOutput = handle_uncaught_exception($exception);

   // If running in a server context...
    if ($exceptionOutput['isServerContext']) {
        // Set the exception's headers (if any).
        foreach ($exceptionOutput['headers'] as $headerKey => $headerValue) {
            header("$headerKey: $headerValue");
        }

        // Set the status code header.
        $httpCode = $exceptionOutput['httpCode'];
        header("{$_SERVER['SERVER_PROTOCOL']} $httpCode ".HttpCodeMessages::$messages[$httpCode]);
    }

   // Print the exception's content.
    echo $exceptionOutput['content'];
} // global_uncaught_exception_handler

set_exception_handler('global_uncaught_exception_handler');

// Configurable constants ---------------------------

$org = $config['organization'];
define('ORGANIZATION_NAME', $org['name']);
define('ORGANIZATION_NAME_ABBREV', $org['name']);

$hierarchy = $config['hierarchy'];
define('HIERARCHY_TOP_LEVEL_LABEL', $hierarchy['top_level_label']);
define('HIERARCHY_TOP_LEVEL_INFO', $hierarchy['top_level_info']);
define('HIERARCHY_MIDDLE_LEVEL_LABEL', $hierarchy['middle_level_label']);
define('HIERARCHY_MIDDLE_LEVEL_INFO', $hierarchy['middle_level_info']);
define('HIERARCHY_BOTTOM_LEVEL_LABEL', $hierarchy['bottom_level_label']);
define('HIERARCHY_BOTTOM_LEVEL_INFO', $hierarchy['bottom_level_info']);
