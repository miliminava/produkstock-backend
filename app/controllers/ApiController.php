<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: ApiController
 * 
 * Automatically generated via CLI.
 */
class ApiController extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->db = $this->call->database();
        $this->call->library('api');
    }

    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login', 5, 60); // Limit to 5 requests per minute

        $input = $this->api->body();
        $identifier = trim((string)($input['username'] ?? $input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Username/email and password are required.', 400);
        }

        $user = $this->db->raw(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1",
            [$identifier, $identifier]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->api->respond_error('Invalid username/email or password.', 401);
        }

        $stored_password = (string)($user['password'] ?? '');
        $valid_password = password_verify($password, $stored_password) || ($stored_password !== '' && hash_equals($stored_password, $password));

        if (!$valid_password) {
            $this->api->respond_error('Invalid username/email or password.', 401);
        }

        $scopes = ['read'];
        if (($user['role'] ?? 'user') === 'admin') {
            $scopes = ['read', 'write', 'delete'];
        } elseif (($user['role'] ?? 'user') === 'moderator') {
            $scopes = ['read', 'write'];
        }

        $tokens = $this->api->issue_tokens([
            'id'    => (int) $user['id'],
            'role'  => $user['role'] ?? 'user',
            'scopes' => $scopes,
        ]);

        $this->api->respond([
            'message' => 'Login successful.',
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'user',
            ],
            'tokens' => $tokens,
        ]);
    }
    public function register()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register', 5, 60);

        $input = $this->api->body();
        $username = trim((string) ($input['username'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $password_confirmation = (string) ($input['password_confirmation'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username)) {
            $this->api->respond_error('Username must be 3-100 characters and use only letters, numbers, dots, underscores, or hyphens.', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->api->respond_error('Enter a valid email address.', 400);
        }

        if (strlen($password) < 8) {
            $this->api->respond_error('Password must be at least 8 characters.', 400);
        }

        if (!hash_equals($password, $password_confirmation)) {
            $this->api->respond_error('Passwords do not match.', 400);
        }

        try {
            $existing_user = $this->db->raw(
                'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
                [$username, $email]
            )->fetch(PDO::FETCH_ASSOC);

            if ($existing_user) {
                $this->api->respond_error('That username or email is already registered.', 409);
            }

            $created = $this->db->table('users')->insert([
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'is_active' => 1,
            ]);

            if (!$created) {
                $this->api->respond_error('Unable to create your account.', 500);
            }

            $user_id = (int) $this->db->last_id();
        } catch (Throwable $exception) {
            error_log('Account registration failed: ' . $exception->getMessage());
            $message = 'Unable to create your account. Check the server database configuration.';
            if (config_item('environment') === 'development') {
                $message .= ' ' . $exception->getMessage();
            }
            $this->api->respond_error($message, 500);
        }

        $this->api->respond([
            'message' => 'Account created successfully.',
            'user' => [
                'id' => $user_id,
                'username' => $username,
                'email' => $email,
                'role' => 'admin',
            ],
        ], 201);
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('logout', 5, 60);

        $input = $this->api->body();
        $token = $this->api->get_bearer_token();

        if (!$token) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $payload = $this->api->require_jwt();
        $user_id = (int)($payload['sub'] ?? 0);

        if ($user_id <= 0) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $refresh_token = (string)($input['refresh_token'] ?? '');

        if ($refresh_token !== '') {
            $this->api->revoke_refresh_token($refresh_token);
        } else {
            $this->db->raw("DELETE FROM refresh_tokens WHERE user_id = ?", [$user_id]);
        }

        $this->api->respond([
            'message' => 'Logout successful.',
            'user_id' => $user_id,
        ]);
    }

    public function add()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('create', 5, 60); // Limit to 5 requests per minute
        $this->authorize_product_action('write');

        $input = $this->api->body();

        $product_name = trim((string)($input['product_name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $price = (float) ($input['price'] ?? 0);
        $quantity = (int) ($input['quantity'] ?? 0);

        if ($product_name === '') {
            $this->api->respond_error('Product name is required.', 400);
        }

        if ($price <= 0) {
            $this->api->respond_error('Price must be greater than zero.', 400);
        }

        if ($quantity < 0) {
            $this->api->respond_error('Quantity cannot be negative.', 400);
        }

        $product_id = $this->db->table('products')->insert([
            'product_name' => $product_name,
            'description' => $description,
            'price' => $price,
            'quantity' => $quantity,
        ]);

        if (!$product_id) {
            $this->api->respond_error('Unable to create product.', 500);
        }

        $product = [
            'id' => (int) $product_id,
            'product_name' => $product_name,
            'description' => $description,
            'price' => number_format($price, 2, '.', ''),
            'quantity' => $quantity,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->api->respond([
            'message' => 'Product created successfully.',
            'product' => $product,
        ], 201);
    }

    public function edit($id = null)
    {
        $this->api->require_method('PUT');
        $this->api->rate_limit('edit', 5, 60); // Limit to 5 requests per minute
        $this->authorize_product_action('write');

        $input = $this->api->body();
        $id = (int)($id ?? ($input['id'] ?? 0));

        if ($id <= 0) {
            $this->api->respond_error('Product ID is required.', 400);
        }

        $existing_product = $this->db->table('products')->where('id', $id)->get();

        if (!$existing_product) {
            $this->api->respond_error('Product not found.', 404);
        }

        $update = [];

        if (array_key_exists('product_name', $input)) {
            $product_name = trim((string) $input['product_name']);
            if ($product_name === '') {
                $this->api->respond_error('Product name is required.', 400);
            }
            $update['product_name'] = $product_name;
        }

        if (array_key_exists('description', $input)) {
            $update['description'] = trim((string) $input['description']);
        }

        if (array_key_exists('price', $input)) {
            $price = (float) $input['price'];
            if ($price <= 0) {
                $this->api->respond_error('Price must be greater than zero.', 400);
            }
            $update['price'] = $price;
        }

        if (array_key_exists('quantity', $input)) {
            $quantity = (int) $input['quantity'];
            if ($quantity < 0) {
                $this->api->respond_error('Quantity cannot be negative.', 400);
            }
            $update['quantity'] = $quantity;
        }

        if (empty($update)) {
            $this->api->respond_error('No product fields were provided for update.', 400);
        }

        $updated = $this->db->table('products')->where('id', $id)->update($update);

        if (!$updated) {
            $this->api->respond_error('Unable to update product.', 500);
        }

        $product = array_merge($existing_product, $update);

        $this->api->respond([
            'message' => 'Product updated successfully.',
            'product' => $product,
        ]);
    }

    public function delete($id = null)
    {
        $this->api->require_method('DELETE');
        $this->api->rate_limit('delete', 5, 60); // Limit to 5 requests per minute
        $this->authorize_product_action('delete');

        $input = $this->api->body();
        $id = (int)($id ?? ($input['id'] ?? 0));

        if ($id <= 0) {
            $this->api->respond_error('Product ID is required.', 400);
        }

        $existing_product = $this->db->table('products')->where('id', $id)->get();

        if (!$existing_product) {
            $this->api->respond_error('Product not found.', 404);
        }

        $deleted = $this->db->table('products')->where('id', $id)->delete();

        if (!$deleted) {
            $this->api->respond_error('Unable to delete product.', 500);
        }

        $this->api->respond([
            'message' => 'Product deleted successfully.',
            'deleted_id' => $id,
        ]);
    }

    public function list()
    {
        $this->api->require_method('GET');
        $this->api->rate_limit('list', 5, 60); // Limit to 5 requests per minute
        $this->authorize_product_action('read');

        $products = $this->db->table('products')->order_by('id', 'ASC')->get_all();

        $this->api->respond([
            'products' => $products ?: [],
        ]);
    }

    private function authorize_product_action($action)
    {
        $payload = $this->api->require_jwt();
        $allowed_roles = [
            'read' => ['admin', 'moderator', 'editor', 'user'],
            'write' => ['admin', 'moderator', 'editor'],
            'delete' => ['admin'],
        ];

        if (!in_array($payload['role'] ?? 'user', $allowed_roles[$action] ?? [], true)) {
            $this->api->respond_error('Forbidden', 403);
        }
    }

}