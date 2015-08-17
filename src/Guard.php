<?php
namespace Slim\Csrf;

use ArrayAccess;
use RuntimeException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * CSRF protection middleware based
 * on the OWASP example linked below.
 *
 * @link https://www.owasp.org/index.php/PHP_CSRF_Guard
 */
class Guard
{
    /**
     * Prefix for CSRF parameters (omit trailing "_" underscore)
     *
     * @var string
     */
    protected $prefix;

    /**
     * CSRF storage
     *
     * @var null|array|ArrayAccess
     */
    protected $storage;

    /**
     * Number of elements to store in the storage array
     *
     * @var integer
     */
    protected $storageLimit = 200;

    /**
     * CSRF Strength
     *
     * @var int
     */
     protected $strength = 16;

    /**
     * Callable to be executed if the CSRF validation fails
     *
     * Signature of callable is:
     *    function($request, $response, $next)
     * and a $response must be returned.
     *
     * @var callable
     */
    protected $failureCallable;

    /**
     * Create new CSRF guard
     *
     * @param string                 $prefix
     * @param null|array|ArrayAccess $storage
     * @param null|callable          $failureCallable
     * @throws RuntimeException if the session cannot be found
     */
    public function __construct($prefix = 'csrf', $storage = null, callable $failureCallable = null)
    {
        $this->prefix = rtrim($prefix, '_');
        if (is_array($storage) || $storage instanceof ArrayAccess) {
            $this->storage = $storage;
        } else {
            if (!isset($_SESSION)) {
                throw new RuntimeException('CSRF middleware failed. Session not found.');
            }
            $this->storage = &$_SESSION;
        }

        $this->setFailureCallable($failureCallable);
    }

    /**
     * Retrieve token name key
     *
     * @return string
     */
    public function getTokenNameKey()
    {
        return $this->prefix . '_name';
    }

    /**
     * Retrieve token value key
     *
     * @return string
     */
    public function getTokenValueKey()
    {
        return $this->prefix . '_value';
    }

    /**
     * Invoke middleware
     *
     * @param  RequestInterface  $request  PSR7 request object
     * @param  ResponseInterface $response PSR7 response object
     * @param  callable          $next     Next middleware callable
     *
     * @return ResponseInterface PSR7 response object
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response, callable $next)
    {
        // Validate POST, PUT, DELETE, PATCH requests
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $body = $request->getParsedBody();
            $body = $body ? (array)$body : [];
            $name = isset($body[$this->prefix . '_name']) ? $body[$this->prefix . '_name'] : false;
            $value = isset($body[$this->prefix . '_value']) ? $body[$this->prefix . '_value'] : false;
            if (!$name || !$value || !$this->validateToken($name, $value)) {
                // Need to regenerate a new token, as the validateToken removed the current one.
                $request = $this->generateNewToken($request);

                $failureCallable = $this->getFailureCallable();
                return $failureCallable($request, $response, $next);
            }
        }
        // Generate new CSRF token
        $request = $this->generateNewToken($request);

        return $next($request, $response);
    }

    /**
     * Generates a new CSRF token and appends it to the request.
     *
     * @param  RequestInterface $request PSR7 response object.
     *
     * @return RequestInterface PSR7 response object.
     */
    protected function generateNewToken($request){
        // Generate new CSRF token
        $name = $this->prefix . mt_rand(0, mt_getrandmax());
        $value = $this->createToken();
        $this->saveToStorage($name, $value);
        $request = $request->withAttribute($this->prefix . '_name', $name)
            ->withAttribute($this->prefix . '_value', $value);

        return $request;
    }

    /**
     * Validate CSRF token from current request
     * against token value stored in $_SESSION
     *
     * @param  string $name  CSRF name
     * @param  string $value CSRF token value
     *
     * @return bool
     */
    protected function validateToken($name, $value)
    {
        $token = $this->getFromStorage($name);
        if (function_exists('hash_equals')) {
            $result = ($token !== false && hash_equals($token, $value));
        } else {
            $result = ($token !== false && $token === $value);
        }
        $this->removeFromStorage($name);

        return $result;
    }

    /**
     * Create CSRF token value
     *
     * @return string
     */
    protected function createToken()
    {
        $token = "";

        if (function_exists("random_bytes")) {
            $rawToken = random_bytes($this->strength);
            if ($rawToken !== false) {
                $token = bin2hex($token);
            }
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            $rawToken = openssl_random_pseudo_bytes($this->strength);
            if ($rawToken !== false) {
                $token = bin2hex($token);
            }
        }

        if ($token == "") {
            if (function_exists("hash_algos") && in_array("sha512", hash_algos())) {
                $token = hash("sha512", mt_rand(0, mt_getrandmax()));
            } else {
                $token = ' ';
                for ($i = 0; $i < 128; ++$i) {
                    $rVal = mt_rand(0, 35);
                    if ($rVal < 26) {
                        $cVal = chr(ord('a') + $rVal);
                    } else {
                        $cVal = chr(ord('0') + $rVal - 26);
                    }
                    $token .= $cVal;
                }
            }
        }

        return $token;
    }

    /**
     * Save token to storage
     *
     * @param  string $name  CSRF token name
     * @param  string $value CSRF token value
     */
    protected function saveToStorage($name, $value)
    {
        $this->storage[$name] = $value;
    }

    /**
     * Get token from storage
     *
     * @param  string      $name CSRF token name
     *
     * @return string|bool CSRF token value or `false` if not present
     */
    protected function getFromStorage($name)
    {
        return isset($this->storage[$name]) ? $this->storage[$name] : false;
    }

    /**
     * Remove token from storage
     *
     * @param  string $name CSRF token name
     */
    protected function removeFromStorage($name)
    {
        $this->storage[$name] = ' ';
        unset($this->storage[$name]);
    }

    /**
     * Remove the oldest tokens from the storage array so that there
     * are never more than storageLimit tokens in the array.
     *
     * This is required as a token is generated every request and so
     * most will never be used.
     */
    protected function enforceStorageLimit()
    {
        while (count($this->storage) > $this->storageLimit) {
            array_shift($this->storage);
        }
    }

    /**
     * Getter for failureCallable
     *
     * @return mixed
     */
    public function getFailureCallable()
    {
        if (is_null($this->failureCallable)) {
            $this->failureCallable = function ($request, $response, $next) {
                $body = new \Slim\Http\Body(fopen('php://temp', 'r+'));
                $body->write('Failed CSRF check!');
                return $response->withStatus(400)->withHeader('Content-type', 'text/plain')->withBody($body);
            };
        }
        return $this->failureCallable;
    }
    
    /**
     * Setter for failureCallable
     *
     * @param mixed $failureCallable Value to set
     * @return self
     */
    public function setFailureCallable($failureCallable)
    {
        $this->failureCallable = $failureCallable;
        return $this;
    }
}
