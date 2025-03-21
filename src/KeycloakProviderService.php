<?php

namespace Edoaurahman\KeycloakSso;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use GuzzleHttp\Exception\ClientException;
use Edoaurahman\KeycloakSso\KeycloakProviderServiceInterface;
use Carbon\Carbon;

class KeycloakProviderService extends AbstractProvider implements ProviderInterface, KeycloakProviderServiceInterface
{
    /**
     * The base URL for Keycloak.
     *
     * @var string
     */
    public $baseUrl;

    /**
     * The Keycloak realm.
     *
     * @var string
     */
    public $realm;

    /**
     * The field name for storing the Keycloak token.
     *
     * @var string
     */
    protected $tokenField;

    /**
     * The field name for storing the Keycloak refresh token.
     *
     * @var string
     */
    protected $refreshTokenField;

    /**
     * Create a new provider instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $clientId
     * @param  string  $clientSecret
     * @param  string  $redirectUrl
     * @param  array  $guzzle
     * @return void
     */
    public function __construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);

        $this->baseUrl = Config::get('keycloak.base_url');
        $this->realm = Config::get('keycloak.realms');
        $this->tokenField = Config::get('keycloak.token_field', 'keycloak_token');
        $this->refreshTokenField = Config::get('keycloak.refresh_token_field', 'keycloak_refresh_token');
    }

    /**
     * Set the base URL for Keycloak.
     *
     * @param string $baseUrl
     * @return void
     */
    public function setBaseUrl($baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * set realm
     * @param string $realm
     * @return void
     */
    public function setRealm($realm): void
    {
        $this->realm = $realm;
    }

    /**
     * Set the token field name.
     *
     * @param string $tokenField
     * @return void
     */
    public function setTokenField($tokenField): void
    {
        $this->tokenField = $tokenField;
    }

    /**
     * Set the refresh token field name.
     *
     * @param string $refreshTokenField
     * @return void
     */
    public function setRefreshTokenField($refreshTokenField): void
    {
        $this->refreshTokenField = $refreshTokenField;
    }

    /**
     * Get the base URL for Keycloak.
     *
     * @return string
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(
            "{$this->baseUrl}realms/{$this->realm}/protocol/openid-connect/auth",
            $state
        );
    }

    /**
     * Get the access token URL for Keycloak.
     *
     * @return string
     */
    protected function getTokenUrl()
    {
        return "{$this->baseUrl}realms/{$this->realm}/protocol/openid-connect/token";
    }

    /**
     * Get the user by token
     *
     * @param string $token
     * @return array
     * @throws \Exception
     */
    protected function getUserByToken($token)
    {
        try {
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/protocol/openid-connect/userinfo",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getUserByToken($newToken);
                }
            }
            throw $e;
        }
    }

    /**
     * Refresh the access token using the refresh token
     *
     * @param string $refreshToken
     * @return string|null New access token or null if refresh failed
     */
    public function refreshToken($refreshToken = null): ?string
    {
        if (!$refreshToken) {
            $user = Auth::user();
            if (!$user || !isset($user->{$this->refreshTokenField})) {
                return null;
            }
            $refreshToken = $user->{$this->refreshTokenField};
            if (!$refreshToken) {
                return null;
            }
        } else if (!Auth::user() || !isset(Auth::user()->{$this->refreshTokenField})) {
            return null;
        }

        try {
            $response = $this->getHttpClient()->post($this->getTokenUrl(), [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            // Store the new tokens
            Session::put([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_in' => $data['expires_in'],
                'token_expiration' => Carbon::now()->addSeconds($data['expires_in'])->timestamp,
            ]);
            // update user's refresh token
            $user = Auth::user();
            if (method_exists($user, 'forceFill') && method_exists($user, 'save')) {
                $user->forceFill([
                    $this->tokenField => $data['access_token'],
                    $this->refreshTokenField => $data['refresh_token'] ?? $refreshToken,
                ])->save();
            }

            return $data['access_token'];
        } catch (\Exception $e) {
            // If refresh token is invalid or expired, redirect to login
            return null;
        }
    }

    /**
     * Function to handle request user, GET POST PUT DELETE etc
     * 
     * @param string $method
     * @param string $url
     * @param array $data
     * @return mixed
     * @throws \Exception
     * 
     */
    public function request($method, $url, $data = []): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);

        // Try to refresh if no token is found
        if (!$token && !($token = $this->refreshToken(Session::get('refresh_token')))) {
            throw new \Exception('Access token not found');
        }

        try {
            $response = $this->getHttpClient()->request($method, $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer {$token}",
                ],
                'json' => $data,
            ]);

            $result = json_decode($response->getBody(), true);

            // Handle null response
            if ($result === null) {
                return [];
            }

            return $result;
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->request($method, $url, $data);
                }
            }
            throw $e;
        }
    }

    /**
     * get client id list
     * 
     * @return array
     * @throws \Exception
     * 
     */
    public function getClientList(): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/clients");
    }


    /**
     * Map the raw user array to a Socialite User instance.
     *
     * @param  array  $user
     * @return \Laravel\Socialite\Two\User
     */
    protected function mapUserToObject(array $user)
    {
        return (new \Laravel\Socialite\Two\User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['picture'] ?? null,
        ]);
    }

    /**
     * get user list
     * 
     * @return array
     * @throws \Exception
     * 
     */
    public function getUserList(): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/users");
    }

    /**
     * get user by id
     * 
     * @param string $id
     * @return array
     * @throws \Exception
     * 
     */
    public function getUser($id): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/users/{$id}");
    }

    /**
     * create user
     * 
     * @param array $data
     * @return array
     * @throws \Exception
     * 
     */
    public function createUser($data): array
    {
        return $this->request('POST', "{$this->baseUrl}admin/realms/{$this->realm}/users", $data);
    }

    /**
     * update user
     * 
     * @param string $id
     * @param array $data
     * @return array
     * @throws \Exception
     * 
     */
    public function updateUser($id, $data): array
    {
        return $this->request('PUT', "{$this->baseUrl}admin/realms/{$this->realm}/users/{$id}", $data);
    }

    /**
     * delete user
     * 
     * @param string $id
     * @return array
     * @throws \Exception
     * 
     */
    public function deleteUser($id): array
    {
        return $this->request('DELETE', "{$this->baseUrl}admin/realms/{$this->realm}/users/{$id}");
    }

    /**
     * regenerate client secret
     * 
     * @param string $id
     * @return array
     * @throws \Exception
     * 
     */
    public function regenerateClientSecret($id): array
    {
        return $this->request('POST', "{$this->baseUrl}admin/realms/{$this->realm}/clients/{$id}/client-secret");
    }

    /**
     * Get Roles of a user
     * 
     * @param string $id
     * @return array
     * @throws \Exception
     * 
     */
    public function getUserRoles($id): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/users/{$id}/role-mappings");
    }

    /**
     * Get all roles for the realm or client
     * 
     * @param string $clientUuid
     * @return array
     * @throws \Exception
     */

    public function getRoles($clientUuid): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/clients/{$clientUuid}/roles");
    }

    /**
     * Get all users with a specific role
     * 
     * @param string $roleName
     * @return array
     * 
     */
    public function getUsersWithRole($roleName): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/roles/{$roleName}/users");
    }

    /**
     * Get all users with their roles
     * 
     * @param string $clientUuid
     * @return array
     * 
     */
    public function getUsersWithRoles($clientUuid): array
    {
        $users = $this->getUserList();
        $roles = $this->getRoles($clientUuid);
        $roleMappings = [];
        foreach ($roles as $role) {
            $roleMappings[$role['id']] = $role['name'];
        }

        foreach ($users as $key => $user) {
            $userRoles = $this->getUserRoles($user['id']);
            $users[$key]['roles'] = [];

            // Process client-specific roles for the requested client
            if (isset($userRoles['clientMappings'])) {
                foreach ($userRoles['clientMappings'] as $clientName => $mapping) {
                    if (isset($mapping['mappings'])) {
                        foreach ($mapping['mappings'] as $role) {
                            if (isset($roleMappings[$role['id']])) {
                                $users[$key]['roles'][] = $roleMappings[$role['id']];
                            }
                        }
                    }
                }
            }

            // Also include realm roles if needed
            if (isset($userRoles['realmMappings'])) {
                foreach ($userRoles['realmMappings'] as $role) {
                    $users[$key]['roles'][] = $role['name'] ?? '';
                }
            }
        }

        return $users;
    }

    /**
     * Create a new role for the realm or client
     * 
     * @param string $clientUuid
     * @param array $data
     * @return array
     * 
     */
    public function createRole($clientUuid, $data): array
    {
        return $this->request('POST', "{$this->baseUrl}admin/realms/{$this->realm}/clients/{$clientUuid}/roles", $data);
    }

    /**
     * Get Client UUID by client ID
     * 
     * @param string $clientId
     * @return string
     * 
     */
    public function getClientUuid($clientId): string
    {
        $clients = $this->getClientList();
        foreach ($clients as $client) {
            if ($client['clientId'] === $clientId) {
                return $client['id'];
            }
        }
        return '';
    }

    /**
     * Get user sessions for client Returns a list of user sessions associated with this client
     * 
     * @param string $clientUuid
     * @return array
     * 
     */
    public function getUserSessions($clientUuid): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/clients/{$clientUuid}/user-sessions");
    }

    /**
     * Get client session stats Returns a JSON map.
     * 
     * @param string $clientUuid
     * @return array
     * 
     */
    public function getClientSessionStats(): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/client-session-stats");
    }

    /**
     * Get sessions associated with the user
     * 
     * @param string $userId
     * @return array
     */
    public function getUserSessionsByUserId($userId): array
    {
        return $this->request('GET', "{$this->baseUrl}admin/realms/{$this->realm}/users/{$userId}/sessions");
    }
    
    /**
     * Retrieves the session details of the currently logged-in user.
     *
     * @return array
     * An array containing details of each session.
     * 
     */
    public function getCurrentUserSessions(): array
    {
        try {// Get token from session or user model
            $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
            
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/account/sessions/devices",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getCurrentUserSessions();
                }
            }
            throw $e;
        }
    }

    /**
     * Retrieves the list of clients associated with the currently logged-in user.
     * 
     * @return array
     * An array containing details of each client.
     * 
     */
    public function getCurrentUserClients(): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/account/applications",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getCurrentUserClients();
                }
            }
            throw $e;
        }
    }
    
    /**
     * Retrieves the authentication credentials associated with the currently logged-in user.
     *
     * @return array
     * An array containing details of user credentials.
     * 
     */
    public function getCurrentUserCredentials(): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/account/credentials",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getCurrentUserCredentials();
                }
            }
            throw $e;
        }
    }
    
    /**
     * Retrieves the profile information of the currently logged-in user.
     * 
     * @return array
     * An array containing user profile details.
     *
     */
    public function getCurrentUserProfile(): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/account",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            $response = json_decode($response->getBody(), true);

            $profile = [
                'id' => $response['id'] ?? null,
                'username' => $response['username'] ?? null,
                'firstName' => $response['firstName'] ?? null,
                'lastName' => $response['lastName'] ?? null,
                'email' => $response['email'] ?? null,
                'emailVerified' => $response['emailVerified'] ?? null,
                'phoneNumber' => isset($response['attributes']) ?
                    ($response['attributes']['phoneNumber'] ?? $response['attributes']['PhoneNumber'] ?? null)
                    : null,
            ];

            return $profile;
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getCurrentUserProfile();
                }
            }
            throw $e;
        }
    }
    
    /**
     * Retrieves the groups associated with the currently logged-in user.
     * 
     * @return array
     * An array containing names of each group.
     * 
     */
    public function getCurrentUserGroups(): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->get(
                "{$this->baseUrl}realms/{$this->realm}/account/groups",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            $response = json_decode($response->getBody(), true);

            $groups = [];

            if(!is_array($response) || empty($response)) {
                return $groups;
            }

            foreach ($response as $group) {
                $groups[] = $group['name'];
            }

            return $groups;
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->getCurrentUserGroups();
                }
            }
            throw $e;
        }
    }

    /**
     * Reset the password of a user by ID.
     * 
     * @param string $userId
     * @param string $newPassword
     * @return array
     * An array containing the response data.
     * 
     */
    public function resetUserPassword($userId, $newPassword): array
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $response = $this->getHttpClient()->put(
                    "{$this->baseUrl}admin/realms/{$this->realm}/users/{$userId}/reset-password",
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                        ],
                        'json' => [
                            "type" => "password",
                            "temporary" => false,
                            "value" => $newPassword,
                        ],
                    ]
                );
    
                if ($response->getStatusCode() === 204) {
                    return [
                        'success' => true,
                        'message' => 'Password has been successfully updated.',
                    ];
                }
    
                return [
                    'success' => false,
                    'message' => 'Failed to update password. Please try again later or contact support.',
                ];
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    $attempt++;
                    sleep(1);
                    continue;
                }
    
                throw $e;
            }
        }

        return [
            'success' => false,
            'message' => 'User does not exist. Please verify your details or create a new account.',
        ];
    }
    
    /**
     * Update the profile of the currently logged-in user.
     * 
     * @param array $data
     * @return array
     * An array containing the response data.
     * 
     */
    public function updateCurrentUserProfile($data): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->post(
                "{$this->baseUrl}realms/{$this->realm}/account",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                    'json' => $data
                ]
            );

            if ($response->getStatusCode() === 204) {
                return [
                    'success' => true,
                    'message' => 'User profile has been successfully updated.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to update user profile. Please try again later or contact support.',
            ];
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->updateCurrentUserProfile($data);
                }
            } else if ($e->getResponse()->getStatusCode() === 400) {
                return [
                    'success' => false,
                    'message' => 'A required user attribute is missing. Please check your input and try again.',
                ];
            }

            throw $e;
        }
    }

    /**
     * Delete all sessions except current session associated with the currently logged-in user.
     * 
     * @return array
     * An array containing the response data.
     * 
     */
    public function deleteAllCurrentUserSessions(): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->delete(
                "{$this->baseUrl}realms/{$this->realm}/account/sessions",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            if ($response->getStatusCode() === 204) {
                return [
                    'success' => true,
                    'message' => 'Sessions deleted successfully.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Unable to delete user sessions. Please try again later or contact support.',
            ];
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->deleteAllCurrentUserSessions();
                }
            }

            throw $e;
        }
    }

    /**
     * Delete a session associated with the currently logged-in user by ID.
     * 
     * @param string $sessionId
     * @return array
     * An array containing the response data.
     * 
     */
    public function deleteCurrentUserSessionById($sessionId): array
    {
        // Get token from session or user model
        $token = Session::get('access_token') ?? (Auth::user() ? Auth::user()->{$this->tokenField} : null);
        
        try {
            $response = $this->getHttpClient()->delete(
                "{$this->baseUrl}realms/{$this->realm}/account/sessions/{$sessionId}",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            );

            if ($response->getStatusCode() === 204) {
                return [
                    'success' => true,
                    'message' => 'Session deleted successfully.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Unable to delete user session. Please try again later or contact support.',
            ];
        } catch (ClientException $e) {
            // Check if token expired (401 Unauthorized)
            if ($e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                $newToken = $this->refreshToken(Session::get('refresh_token'));
                if ($newToken) {
                    // Retry with the new token
                    return $this->deleteCurrentUserSessionById($sessionId);
                }
            }

            throw $e;
        }
    }

    /**
     * Send a verification email to a user to verify their email address.
     * 
     * @param string $userId
     * @return array
     * An array containing the response data.
     * 
     */
    public function sendVerificationEmail($userId): array
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $response = $this->getHttpClient()->put(
                    "{$this->baseUrl}admin/realms/{$this->realm}/users/{$userId}/send-verify-email",
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]
                );
    
                if ($response->getStatusCode() === 204) {
                    return [
                        'success' => true,
                        'message' => 'Verification email has been sent successfully.',
                    ];
                }
    
                return [
                    'success' => false,
                    'message' => 'Failed to send verification email. Please try again later.',
                ];
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    $attempt++;
                    sleep(1);
                    continue;
                }
    
                throw $e;
            }
        }

        return [
            'success' => false,
            'message' => 'User does not exist. Please verify your details or create a new account.',
        ];
    }

    /**
     * Send a reset password email to a user to reset their password.
     * 
     * @param string $userId
     * @return array
     * An array containing the response data.
     * 
     */
    public function sendResetPasswordEmail($userId): array
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $response = $this->getHttpClient()->put(
                    "{$this->baseUrl}admin/realms/{$this->realm}/users/{$userId}/reset-password-email",
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]
                );
    
                if ($response->getStatusCode() === 204) {
                    return [
                        'success' => true,
                        'message' => 'Reset password email has been sent successfully.',
                    ];
                }
    
                return [
                    'success' => false,
                    'message' => 'Failed to send reset password email. Please try again later.',
                ];
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    $attempt++;
                    sleep(1);
                    continue;
                }
    
                throw $e;
            }
        }

        return [
            'success' => false,
            'message' => 'User does not exist. Please verify your details or create a new account.',
        ];
    }
}