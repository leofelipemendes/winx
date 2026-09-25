<?php

namespace Tests\Feature\Repositories;

use App\Models\User;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BaseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): BaseRepository
    {
        return new class(new User) extends BaseRepository {};
    }

    public function test_it_can_persist_read_update_and_delete_a_model(): void
    {
        $repository = $this->repository();
        $attributes = User::factory()->raw();
        unset($attributes['email_verified_at'], $attributes['remember_token']);
        $user = $repository->create($attributes);

        $this->assertTrue($repository->find($user->id)->is($user));
        $this->assertTrue($repository->findOrFail($user->id)->is($user));
        $this->assertSame('Updated name', $repository->update($user->id, ['name' => 'Updated name'])->name);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated name']);
        $this->assertTrue($repository->delete($user->id));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertNull($repository->find($user->id));
    }

    public function test_pagination_has_stable_order_and_total(): void
    {
        $users = User::factory()->count(5)->create();
        $page = $this->repository()->paginate(perPage: 2, page: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(2, $page->currentPage());
        $this->assertSame($users->slice(2, 2)->pluck('id')->all(), $page->getCollection()->pluck('id')->all());
    }

    #[DataProvider('missingOperations')]
    public function test_missing_records_raise_an_explicit_exception(string $operation): void
    {
        $this->expectException(ModelNotFoundException::class);

        if ($operation === 'update') {
            $this->repository()->update(999, ['name' => 'Missing']);
        } else {
            $this->repository()->{$operation}(999);
        }
    }

    public static function missingOperations(): array
    {
        return [['findOrFail'], ['update'], ['delete']];
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_is_rejected(int $perPage, int $page): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository()->paginate($perPage, $page);
    }

    public static function invalidPagination(): array
    {
        return [[0, 1], [101, 1], [15, 0]];
    }
}
