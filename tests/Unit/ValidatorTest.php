<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testEmailNormalisedAndValidated(): void
    {
        $v = new Validator();
        $this->assertSame('user@example.com', $v->email('email', '  User@Example.com '));
        $this->assertFalse($v->fails());
    }

    public function testInvalidEmailFails(): void
    {
        $v = new Validator();
        $this->assertNull($v->email('email', 'not-an-email'));
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors());
    }

    public function testPasswordTooShortFails(): void
    {
        $v = new Validator();
        $this->assertNull($v->password('password', 'short'));
        $this->assertTrue($v->fails());
    }

    public function testCommonPasswordFails(): void
    {
        $v = new Validator();
        $this->assertNull($v->password('password', 'password1'));
        $this->assertTrue($v->fails());
    }

    public function testStrongPasswordPasses(): void
    {
        $v = new Validator();
        $this->assertSame('Tr0ub4dour-Xyz', $v->password('password', 'Tr0ub4dour-Xyz'));
        $this->assertFalse($v->fails());
    }

    public function testVisibilityDefaultAndValidation(): void
    {
        $v = new Validator();
        $this->assertSame('private', $v->visibility('visibility', null));
        $this->assertSame('public', $v->visibility('visibility', 'PUBLIC'));
        $this->assertFalse($v->fails());

        $v2 = new Validator();
        $v2->visibility('visibility', 'sometimes');
        $this->assertTrue($v2->fails());
    }

    public function testRouteSourceValidation(): void
    {
        $v = new Validator();
        $this->assertSame('strava', $v->routeSource('source', 'strava'));
        $v->routeSource('source', 'garmin');
        $this->assertTrue($v->fails());
    }

    #[DataProvider('invalidHandles')]
    public function testPublicHandleRejectsInvalid(string $handle): void
    {
        $v = new Validator();
        $this->assertNull($v->publicHandle('public_handle', $handle));
        $this->assertTrue($v->fails());
    }

    public static function invalidHandles(): array
    {
        return [
            'too short'        => ['a'],
            'empty'            => [''],
            'leading under'    => ['_bob'],
            'double under'     => ['foo__bar'],
            'uppercase'        => ['Bob'],
            'reserved word'    => ['admin'],
            'illegal char'     => ['bob-x'],
        ];
    }

    public function testPublicHandleAcceptsValid(): void
    {
        $v = new Validator();
        $this->assertSame('gravel_bob_42', $v->publicHandle('public_handle', 'gravel_bob_42'));
        $this->assertFalse($v->fails());
    }

    /** Untergrenze ist jetzt 2 Zeichen (zuvor 3). */
    public function testPublicHandleAcceptsTwoChars(): void
    {
        $v = new Validator();
        $this->assertSame('ab', $v->publicHandle('public_handle', 'ab'));
        $this->assertFalse($v->fails());
    }

    public function testTagsNormalisedAndDeduped(): void
    {
        $v = new Validator();
        $tags = $v->tags('tags', ['Gravel', 'gravel', ' Mountains ', '']);
        $this->assertSame(['gravel', 'mountains'], $tags);
        $this->assertFalse($v->fails());
    }
}
