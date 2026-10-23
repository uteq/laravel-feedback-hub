<?php

use Uteq\FeedbackHub\Tests\Support\User;

it('hands a signed-in user the current csrf token', function (): void {
    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $this->actingAs($user)
        ->withSession(['_token' => 'session-token'])
        ->getJson(route('feedback-hub.csrf'))
        ->assertOk()
        ->assertExactJson(['token' => 'session-token']);
});

it('answers 401 instead of a token when the user is signed out', function (): void {
    $this->getJson(route('feedback-hub.csrf'))->assertUnauthorized();
});

it('is not shadowed by the hub admin report route', function (): void {
    expect(route('feedback-hub.csrf', absolute: false))->toBe('/feedback-hub/session/csrf');
});
