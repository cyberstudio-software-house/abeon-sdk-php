<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\Exceptions\InvalidQueryException;
use Abeon\SDK\Http\QueryParser;
use Abeon\SDK\Tests\Support\UsesMariaDb;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParserTest extends TestCase
{
    use UsesMariaDb;

    private const FILTERS = ['status'];

    private const SORTS = ['joined_at', 'email'];

    private function parse(string $query, string $defaultSort = ''): \Abeon\SDK\Http\QuerySpec
    {
        return QueryParser::parse(Request::create('/list?'.$query), self::FILTERS, self::SORTS, $defaultSort);
    }

    public function test_nothing_asked_means_no_filters_no_sort_and_no_page(): void
    {
        $spec = $this->parse('');

        $this->assertSame([], $spec->filters);
        $this->assertSame([], $spec->sorts);
        $this->assertFalse($spec->isPaginated());
    }

    public function test_it_reads_filters_sorts_and_the_page(): void
    {
        $spec = $this->parse('filter[status]=active&sort=-joined_at,email&page=2&per_page=10');

        $this->assertSame(['status' => 'active'], $spec->filters);
        $this->assertSame([['joined_at', 'desc'], ['email', 'asc']], $spec->sorts);
        $this->assertTrue($spec->isPaginated());
        $this->assertSame(2, $spec->pageOrFirst());
        $this->assertSame(10, $spec->perPageOr(25));
    }

    public function test_per_page_alone_asks_for_the_first_page(): void
    {
        $spec = $this->parse('per_page=5');

        $this->assertTrue($spec->isPaginated());
        $this->assertSame(1, $spec->pageOrFirst());
    }

    public function test_the_default_sort_applies_only_when_none_is_given(): void
    {
        $this->assertSame([['joined_at', 'desc']], $this->parse('', '-joined_at')->sorts);
        $this->assertSame([['email', 'asc']], $this->parse('sort=email', '-joined_at')->sorts);
    }

    public function test_a_repeated_sort_field_counts_once(): void
    {
        $this->assertSame([['email', 'desc']], $this->parse('sort=-email,email')->sorts);
    }

    public function test_a_default_sort_outside_the_allowlist_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        $this->parse('', 'password');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'unknown filter' => ['filter[password]=x', 'filter.password'];
        yield 'filter as a list' => ['filter[status][]=a&filter[status][]=b', 'filter.status'];
        yield 'filter not an object' => ['filter=active', 'filter'];
        yield 'unknown sort' => ['sort=-password', 'sort'];
        yield 'page zero' => ['page=0', 'page'];
        yield 'page not a number' => ['page=two', 'page'];
        yield 'negative per_page' => ['per_page=-5', 'per_page'];
        yield 'per_page over the cap' => ['per_page=101', 'per_page'];
    }

    #[DataProvider('refusedQueries')]
    public function test_an_unsupported_query_is_refused_not_ignored(string $query, string $errorKey): void
    {
        try {
            $this->parse($query);
            $this->fail("{$query} was accepted");
        } catch (InvalidQueryException $e) {
            $this->assertSame(422, $e->status());
            $this->assertArrayHasKey($errorKey, $e->problem->extensions['errors']);
        }
    }

    public function test_the_spec_filters_sorts_and_pages_a_real_query(): void
    {
        $capsule = $this->connectTestDatabase(new Container());
        $db = $capsule->getConnection();
        $db->getSchemaBuilder()->create('members', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('status');
            $table->timestamp('created_at');
        });
        $db->table('members')->insert([
            ['email' => 'a@x.pl', 'status' => 'active', 'created_at' => '2026-01-01 10:00:00'],
            ['email' => 'b@x.pl', 'status' => 'suspended', 'created_at' => '2026-02-01 10:00:00'],
            ['email' => 'c@x.pl', 'status' => 'active', 'created_at' => '2026-03-01 10:00:00'],
        ]);

        $spec = $this->parse('filter[status]=active&sort=-joined_at&per_page=1&page=2');
        $query = $spec->apply($db->table('members'), ['status' => 'status', 'joined_at' => 'created_at', 'email' => 'email']);

        $this->assertSame(2, (clone $query)->count());
        $this->assertSame(['a@x.pl'], $query->forPage($spec->pageOrFirst(), $spec->perPageOr(25))->pluck('email')->all());
    }

    public function test_applying_a_field_without_a_column_is_a_programming_error(): void
    {
        $capsule = new Capsule(new Container());
        $capsule->addConnection(['driver' => 'mariadb', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => 'x']);

        $this->expectException(LogicException::class);

        $this->parse('filter[status]=active')->apply($capsule->getConnection()->table('members'), []);
    }
}
