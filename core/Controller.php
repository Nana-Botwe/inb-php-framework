<?php

declare(strict_types=1);

namespace Core;

/**
 * Base controller. Actions return a Response (or a string / array / model, which are converted).
 */
abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $view, array $data = [], int $status = 200): Response
    {
        return view($view, $data, $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** @param array<string, mixed> $params */
    protected function redirectToRoute(string $name, array $params = []): Response
    {
        return Response::redirect(App::instance()->router->url($name, $params));
    }

    protected function back(): Response
    {
        return back();
    }

    /**
     * Validate the request; on failure the user is sent back with errors and old input
     * (or receives a 422 JSON response for API requests). Returns only validated fields.
     *
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        return $request->validate($rules, $messages, $attributes);
    }

    /**
     * Stop with 403 unless allowed:
     *   $this->authorize('update', $post);                 // policy / Gate ability (recommended)
     *   $this->authorize($post->user_id === Auth::id());   // a plain condition
     */
    protected function authorize(bool|string $abilityOrAllowed, mixed ...$arguments): void
    {
        if (is_string($abilityOrAllowed)) {
            Auth\Gate::authorize($abilityOrAllowed, ...$arguments);

            return;
        }

        if (!$abilityOrAllowed) {
            Log::security('authorization_denied', ['controller' => static::class]);
            throw new HttpException(403, is_string($arguments[0] ?? null) ? $arguments[0] : 'You are not allowed to do this.');
        }
    }
}
