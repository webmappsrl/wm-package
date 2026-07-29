<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Jenssegers\Agent\Facades\Agent;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\User;

class AppAuthController extends Controller
{
    /** * Signup and get a JWT
     *
     */
    public function signup(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            array_merge([
                'email' => 'required|email|unique:users,email',
                'password' => 'required',
                'name' => 'required|string|max:255',
            ], $this->getPrivacyRules()),
            array_merge([
                'email.email' => 'Il campo email deve essere un indirizzo email valido.',
                'email.unique' => 'Un utente è già stato registrato con questa email.',
                'email.required' => 'Il campo email è obbligatorio.',
                'password.required' => 'Il campo password è obbligatorio.',
                'name.required' => 'Il campo nome è obbligatorio.',
            ], $this->getPrivacyMessages())
        );

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 400,
            ], 400);
        }

        $credentials = $request->only(['email', 'password', 'name']);
        $credentials['email'] = strtolower($credentials['email']);

        $privacy = $request->input('privacy');
        $appId = $request->header('app-id');

        try {
            $user = $this->createUser($credentials, $privacy, $appId);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 400,
            ], 400);
        }

        if ($token = auth('api')->attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return $this->loginResponse($token);
        }

        return response()->json(['error' => 'Unauthorized'], 401);
    }

    /**
     * Get a JWT via given credentials.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|email',
                'password' => 'required|min:6',
            ],
            [
                'email.required' => 'Il campo email é obbligatorio.',
                'email.email' => 'Il campo email deve essere un indirizzo email valido.',
                'password.required' => 'Il campo password è obbligatorio.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 401,
            ], 401);
        }

        $credentials = $request->only(['email', 'password']);
        $credentials['email'] = strtolower($credentials['email']);

        // check if email exists
        $user = User::where('email', $credentials['email'])->first();
        if (! $user) {
            return response()->json([
                'error' => 'L\'email inserita non è corretta. Per favore, riprova.',
                'code' => 401,
            ], 401);
        }

        // Check if password is correct
        if (! auth('api')->attempt($credentials)) {
            return response()->json([
                'error' => 'La password inserita non è corretta. Per favore, riprova.',
                'code' => 401,
            ], 401);
        }

        $referrer = Str::substr($request->input('referrer'), 0, 30);
        $isMobileRequest = Agent::isMobile();
        if ($referrer && $isMobileRequest && ! $user->app->sku->contains($referrer)) {
            // TODO: mv to service and validate os!
            $os = Str::substr(Str::trim(Str::lower(Agent::platform())), 0, 30);
            if (! $user->app->sku->has($os)) {
                $user->app->sku->put($os, $referrer);
                $user->app->save();
            }
        }

        $token = auth('api')->attempt($credentials);

        return $this->loginResponse($token);
    }

    /**
     * Delete the authenticated user.
     *
     * This function deletes the user that is currently authenticated via the API.
     *
     * @return JsonResponse The JSON response containing a success message if the user was deleted successfully,
     *                      or an error message and code if the deletion failed.
     */
    public function delete(): JsonResponse
    {
        try {
            // Get the authenticated user from the API
            $user = auth('api')->user();

            // Logout the user from the API and delete the user
            auth('api')->logout();
            $user->delete();
        } catch (Exception $e) {
            // If an exception occurs, return a JSON response with the error message and code
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 400,
            ], 400);
        }

        // If the user was deleted successfully, return a JSON response with a success message
        return response()->json(['success' => 'Account utente cancellato con successo.']);
    }

    /**
     * Get the authenticated User.
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (! $user) {
                return response()->json(['error' => 'Utente non autenticato.'], 401);
            }
            $appId = $request->header('app-id');
            if ($appId) {
                $user = $this->filterUserPrivacyByAppId($user, $appId);
            }

            return response()->json($user);
        } catch (Exception $e) {
            \Log::error('Errore nel metodo me: '.$e->getMessage());

            return response()->json(['error' => 'Errore interno.'], 500);
        }
    }

    /**
     * Log the user out (Invalidate the token).
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(['message' => 'Logout effettuato con successo.']);
    }

    /**
     * Refresh a token.
     */
    public function refresh(): JsonResponse
    {
        try {
            /** @phpstan-ignore method.notFound */
            return $this->respondWithToken(auth('api')->refresh());
        } catch (Exception $e) {
            return response()->json(['error' => 'Impossibile aggiornare il token.'], 401);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.auth('api')->id(),
            'password' => 'sometimes|string|min:6',
            'properties' => 'sometimes|array',
            'properties.*' => 'sometimes',
            'app_id' => 'sometimes|integer',
            'surname' => 'sometimes|nullable|string|max:255',
            'avatar' => 'sometimes|file|image|max:5120',
        ], $this->getPrivacyRules()), array_merge([
            'name.string' => 'Il campo nome deve essere una stringa.',
            'name.max' => 'Il campo nome non può superare i 255 caratteri.',
            'email.email' => 'Il campo email deve essere un indirizzo email valido.',
            'email.unique' => 'Un utente è già stato registrato con questa email.',
            'password.min' => 'La password deve essere di almeno 6 caratteri.',
            'properties.array' => 'Il campo properties deve essere un array.',
            'surname.string' => 'Il campo cognome deve essere una stringa.',
            'surname.max' => 'Il campo cognome non può superare i 255 caratteri.',
            'avatar.image' => 'Il file caricato deve essere un\'immagine.',
            'avatar.max' => 'L\'immagine non può superare i 5MB.',
        ], $this->getPrivacyMessages()));

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
                'code' => 400,
            ], 400);
        }

        try {
            $userId = auth('api')->user()->id;
            $user = User::find($userId);
            $updateData = [];

            // Update basic user fields
            $name = $request->input('name');
            $email = $request->input('email');
            $password = $request->input('password');
            $properties = $request->input('properties');
            $privacy = $request->input('privacy');
            $surname = $request->input('surname');

            if ($name) {
                $updateData['name'] = $name;
            }
            if ($email) {
                $updateData['email'] = strtolower($email);
            }
            if ($password) {
                $updateData['password'] = bcrypt($password);
            }
            // Laravel converte le stringhe vuote in null prima della validazione
            // (ConvertEmptyStringsToNull, middleware di default) — usare has()
            // invece del valore per distinguere "campo non inviato" da "utente ha
            // svuotato il campo", altrimenti "cancella il cognome" è un no-op.
            if ($request->has('surname')) {
                $updateData['surname'] = (string) $surname;
            }

            // Handle properties update
            if ($properties) {
                $currentProperties = $user->properties ?? [];
                // Merge with existing properties
                $updateData['properties'] = array_merge($currentProperties, $properties);
            }

            // Handle privacy object if provided (new format)
            if ($privacy) {
                $appId = $request->header('app-id');
                $user = $this->updatePrivacyAgree($user, $privacy, $appId);
            }

            // Update user with basic fields
            if (! empty($updateData)) {
                $user->update($updateData);
            }

            // Handle avatar upload (must run after $user->update() above, since
            // addMediaFromRequest/addMedia operates on the already-persisted model).
            if ($request->hasFile('avatar')) {
                $strippedPath = $this->stripExifFromUploadedImage($request->file('avatar'));
                $mediaAdder = $user->addMedia($strippedPath)
                    ->usingFileName($request->file('avatar')->hashName());

                $appIdHeader = $request->header('app-id');
                if ($appIdHeader) {
                    // Pass app_id explicitly so MediaObserver doesn't fall back to the
                    // hardcoded app_id = 1 default (FK violation / cross-tenant data).
                    $mediaAdder = $mediaAdder->withAttributes(['app_id' => (int) $appIdHeader]);
                }

                $mediaAdder->toMediaCollection('avatar');
            }

            $user = $user->fresh();

            $appIdForResponse = $request->header('app-id');
            $user = $appIdForResponse
                ? $this->filterUserPrivacyByAppId($user, $appIdForResponse)
                : $user->toArray();

            return response()->json($user);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Errore durante l\'aggiornamento dell\'utente: '.$e->getMessage(),
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Get the token array structure.
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            /** @phpstan-ignore method.notFound */
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    /**
     * Generate the login response with user data and token.
     */
    protected function loginResponse(string $token): JsonResponse
    {
        $tokenArray = $this->respondWithToken($token);

        return response()->json(array_merge($this->me(request())->getData(true), $tokenArray->getData(true)));
    }

    /**
     * Create a new user
     *
     * @throws Exception
     */
    private function createUser(array $data, ?array $privacy, ?string $appId): User
    {
        $user = new User;
        $user->fill([
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'name' => $data['name'],
            'email_verified_at' => now(),
        ]);

        if ($privacy && $appId) {
            $user = $this->updatePrivacyAgree($user, $privacy, $appId);
        }

        try {
            $user->save();
        } catch (Exception $e) {
            throw new Exception('Errore durante il salvataggio dell\'utente. Per favore, riprova.');
        }

        // A failed dispatch (e.g. queue connection down) must never fail signup: the
        // user row is already committed above, so propagating this exception would
        // make signup()'s outer catch return a 400 to the client while the account
        // was actually created, leaving a client retry stuck on email.unique.
        try {
            FetchGravatarAvatarJob::dispatch($user->id, $appId ? (int) $appId : null);
        } catch (Exception $e) {
            \Log::error('Failed to dispatch Gravatar job: '.$e->getMessage());
        }

        return $user;
    }

    /**
     * Update the privacy agree for the user.
     *
     * @param  User  $user  The user to update the privacy agree for.
     * @param  array  $privacyAgree  The privacy agree to update.
     * @return User The updated user.
     */
    private function updatePrivacyAgree(User $user, array $privacyAgree, string $appId): User
    {
        $properties = $user->properties ?? [];

        // Initialize privacy if not exists
        if (! isset($properties['privacy'])) {
            $properties['privacy'] = [];
        }

        // Initialize app_id array if not exists
        if (! isset($properties['privacy'][$appId])) {
            $properties['privacy'][$appId] = [];
        }

        $properties['privacy'][$appId][] = $privacyAgree;

        $user->properties = $properties;
        $user->saveQuietly();

        return $user;
    }

    /**
     * Format user data for API response with privacy filtered by app_id.
     */
    private function filterUserPrivacyByAppId(User $user, string $appId): array
    {
        $userArray = $user->toArray();

        if (! isset($userArray['properties'])) {
            $userArray['properties'] = [];
        }

        if (isset($userArray['properties']['privacy'])) {
            $privacy = $userArray['properties']['privacy'];
            $userArray['properties']['privacy'] = isset($privacy[$appId])
                ? array_values($privacy[$appId])
                : [];
        } else {
            $userArray['properties']['privacy'] = [];
        }

        unset($userArray['password']);

        return $userArray;
    }

    /**
     * Get common validation rules for privacy
     */
    private function getPrivacyRules(): array
    {
        return [
            'privacy' => 'sometimes|array',
            'privacy.agree' => 'required_with:privacy|boolean',
            'privacy.date' => 'required_with:privacy|date',
        ];
    }

    /**
     * Get common validation messages for privacy
     */
    private function getPrivacyMessages(): array
    {
        return [
            'privacy.array' => 'Il campo privacy deve essere un oggetto.',
            'privacy.agree.required_with' => 'Il campo agree è obbligatorio quando privacy è fornito.',
            'privacy.agree.boolean' => 'Il campo agree deve essere true o false.',
            'privacy.date.required_with' => 'Il campo date è obbligatorio quando privacy è fornito.',
            'privacy.date.date' => 'Il campo date deve essere una data valida.',
        ];
    }

    /**
     * Re-encode the uploaded image via GD (Intervention Image) to strip all EXIF
     * metadata, including GPS coordinates. orientate() bakes the correct rotation
     * in before the re-encode discards the EXIF orientation tag.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return string absolute path to a stripped temp copy
     */
    private function stripExifFromUploadedImage($file): string
    {
        // extension() guesses the extension from the actual file content/MIME type
        // (via Symfony's MimeTypes guesser), unlike getClientOriginalExtension()
        // which trusts the client-supplied filename and can be fooled by a
        // mismatched or missing extension (e.g. a real JPEG uploaded as "photo.txt"
        // passes Laravel's content-based `image` validation, but the encoder below
        // would then crash trying to encode to the "txt" format).
        $strippedPath = sys_get_temp_dir().'/'.Str::random(20).'.'.$file->extension();

        Image::make($file->getRealPath())
            ->orientate()
            ->save($strippedPath);

        return $strippedPath;
    }
}
