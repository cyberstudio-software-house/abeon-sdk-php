# Code Review — `abeon/sdk` (Phase 0)

**Reviewer:** Michał Mucha (z udziałem 3 równoległych code-analyst agentów)
**Date:** 2026-05-15
**Scope:** Sprint 0-3 implementation (10 commitów, ~3200 linii PHP w `src/`)
**Method:** trzy niezależne perspektywy — security audit, correctness/reliability audit, code quality + Laravel conventions audit. Wyniki sklasyfikowano i poddano fact-check (część findings agentów została odrzucona jako overstated lub zduplikowane).

---

## Executive summary

| Aspekt | Ocena | Komentarz |
|---|---|---|
| **Architektura** | 9 / 10 | Strict layering (Core → Auth → Events → Client → Services), federacja schematów, decyzje modularności M1-M7 zaaplikowane. |
| **Correctness** | 7 / 10 | Outbox + idempotency są poprawne pod jedną repliką per worker; race conditions występują tylko gdy konwencja (1 replica) jest złamana. |
| **Security** | 7 / 10 | Brak twardych vuln-ów. Główne braki: input validation correlation header (CRLF), path traversal w SchemaDiscovery, brak retry/timeout. |
| **Code quality** | 8 / 10 | Strict types wszędzie, readonly DTOs, fromArray/toArray symetria. Pain points: UUID duplikacja, JsonFormatter stub. |
| **Test coverage** | 0 / 10 | **Brak testów PHP.** Składnia walidowana w Dockerze; `composer install` jeszcze nie odpalony, PHPUnit nie zainstalowany. Krytyczna luka. |
| **Documentation** | 9 / 10 | 5 ADR-ów + README + usage.md + events-catalog.md = solidna baza. |

**Verdict:** **Production-ready pod jeden warunek** — dodać testy. Reszta findings to ulepszenia, które można aplikować inkrementalnie podczas używania SDK przez pierwsze 2 serwisy (Faza 1 Auth + CRM). Architecture jest poprawna; gaps są w validation boundaries i runtime safety nets.

---

## Top 5 action items (priorytet)

1. **HI-1 + DOK:** Dodać runtime check / explicit docs że `EventPublisher::publish()` MUSI być wewnątrz `DB::transaction()`. Bez tego silent split publish/business write.
2. **HI-4:** Naprawić topic wildcard regex `#` (zamiast `.+` użyj `.*` — zgodnie z AMQP spec "zero+").
3. **HI-2 + HI-3:** Dokumentować "handlers must be idempotent" jako twardy wymóg; zawęzić catch w ProcessedEvents do unique-constraint exception (rethrow connection errors).
4. **TESTY:** Dodać `composer install` + PHPUnit unit tests dla minimum: `JwtValidator`, `RoutingKey`, `EnvelopeBuilder`, `ProcessedEvents`. Bez testów nie ma SDK 1.0.
5. **MD-1:** Walidacja inbound `X-Correlation-ID` — UUID regex check, max 36 chars, sanityzacja przed log.

---

## Critical findings (1)

### CR-1 — OutboxDrainer brak FOR UPDATE SKIP LOCKED

**Location:** `src/Events/OutboxDrainer.php:113-128` (`fetchBatch()`)

**Issue:**
```php
return $this->table()
    ->whereNull('processed_at')
    ->where(function (Builder $q) use ($now): void {
        $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
    })
    ->where('attempts', '<', $this->config->outboxMaxAttempts())
    ->orderBy('id')
    ->limit($this->config->outboxBatchSize())
    ->get()
    ->all();
```

Brak `->lockForUpdate()` (lub raw `FOR UPDATE SKIP LOCKED`).

**Scenario:** Deploy z 2+ replikami `OutboxDrainer`. Obie pollują tę samą tabelę w tym samym `poll_interval` window — obie fetchują te same wiersze, obie publikują do RabbitMQ → duplikaty w brokerze.

**Impact:** Łagodzone przez consumer-side dedup (`abeon_processed_events`), więc business-impact = zero correctness loss, ale:
- Marnowane bandwidth + broker work (N kopii każdego event-u)
- Plan SDK eksplicytnie mówi "1 replica" — konwencja dokumentowana, ale brak enforcement w kodzie.

**Severity downgrade:** Agent flagował jako Critical "broken exactly-at-least-once". Po fact-check: contract jest "at-least-once" (nie "exactly-once") i consumer dedupes → nie jest złamany. Ale wciąż wart fix dla operational hygiene.

**Suggested fix:**
- Dodać `->lockForUpdate()` w `fetchBatch()`.
- Konfigurowalny `SKIP LOCKED` (MariaDB 10.3+, PostgreSQL standard) — fallback graceful gdy DB nie wspiera.
- Test integracyjny: 2 drainery na tym samym docker-compose RabbitMQ + MariaDB → zero duplikatów na broker side.

---

## High severity findings (5)

### HI-1 — OutboxPublisher::publish() nie wymusza ani nie sprawdza transakcji

**Location:** `src/Events/OutboxPublisher.php:30-44`

**Issue:** Publisher zakłada że caller wywołuje go wewnątrz `DB::transaction()` — ale nie ma żadnej weryfikacji ani jasnego komunikatu błędu jeśli wywołanie nastąpi poza transakcją.

**Scenario:**
```php
// W kontrolerze serwisu — bez transakcji:
$contact = Contact::create($data);
$publisher->publish('crm.contact.created', [...]);  // commituje się od razu
// ...następnie:
throw new ValidationException(...);  // Contact::create także rolluje się... ale event już opublikowany.
```

Result: business state cofnięty, event opublikowany. Klasyczny outbox bug.

**Impact:** Cichy data inconsistency. Inne serwisy konsumują event który "się nie zdarzył" w business-state perspective.

**Suggested fix:**
1. W `OutboxPublisher::publish()` sprawdź `DB::transactionLevel() > 0`; jeśli 0 → throw `ContractViolationException::publishOutsideTransaction()`.
2. Dodać alternatywę: `publishStandalone()` dla przypadków świadomych (np. system events).
3. Wzmocnić docblock + przykład w README.

```php
public function publish(...): string {
    if ($this->db->connection($this->config->outboxConnection())->transactionLevel() === 0) {
        throw ContractViolationException::publishOutsideTransaction($routingKey);
    }
    // ... istniejący kod
}
```

### HI-2 — ProcessedEvents race między isProcessed() a markProcessed() (concurrent delivery)

**Location:** `src/Events/EventConsumer.php:127-160`, `src/Events/ProcessedEvents.php:25-49`

**Issue:** Sekwencja:
1. `isProcessed($eventId)` → `false`
2. handler runs (możliwe side effects: zapisy DB, calls HTTP, emails)
3. `markProcessed($eventId, $routingKey)` → catches `QueryException` jako duplicate

Jeśli dwóch consumer-ów (lub jeden consumer dwa razy przy retry) wykona kroki 1-2 równolegle przed wykonaniem kroku 3 jakiegokolwiek z nich — handler odpali się 2x.

**Scenario:** Drainer publikuje event 2x (race CR-1) → broker dostarcza 2 kopie → consumer A i B (lub jeden w 2 wątkach) widzą `isProcessed=false` równocześnie → obie wywołują handler → first markProcessed succeeds, second rzuca `QueryException` na unique constraint → ale handler już odpalił 2x.

**Impact:** Side effects 2x: duplicate emaile, duplicate invoice, duplicate notyfikacja. Idempotency consumer-side jest implicit-mandatory dla handlerów.

**Suggested fix:**
- **Dokumentacja:** Twarda zasada w README + ADR-0002: "**EventHandler implementations MUST be idempotent.** SDK wspiera dedup via abeon_processed_events, ale to second-line defense — handler musi być safe pod re-execution."
- **Kod:** Alternatywa silniejsza: użyć `mark-then-handle` (insert do processed_events PRZED handlerem) — wtedy duplicate delivery silently skipped, ale jeśli handler się wywali, event jest "processed" without business effect (gorzej).
- **Compromise:** Aktualny pattern (handle-then-mark) + udokumentowane wymaganie idempotency w handlerach jest pragmatic. Tylko trzeba to napisać explicitly.

### HI-3 — ProcessedEvents.markProcessed() catches all QueryException jako "duplicate"

**Location:** `src/Events/ProcessedEvents.php:39-49`

**Issue:**
```php
try {
    $this->table()->insert([...]);
    return true;
} catch (QueryException) {
    return false;
}
```

`QueryException` to base class dla wszystkich Laravel DB exceptions — connection refused, deadlock, syntax error, permission denied, foreign key violation. Wszystkie traktowane jako "duplicate".

**Scenario:** Database connection drops podczas insert → `QueryException` → caller (EventConsumer) widzi `false` → traktuje jako "already processed" → ack message → event się gubi.

**Impact:** Silent event loss przy transient DB errors. Trudne do debugowania ("event nie dotarł" — w logach success ack).

**Suggested fix:**
```php
} catch (QueryException $e) {
    // 23000 = SQLSTATE integrity constraint violation (unique key)
    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
        return false;
    }
    throw $e;  // genuine errors propagate up — EventConsumer nacks → message requeued
}
```

### HI-4 — Topic wildcard regex `#` semantics wrong (AMQP spec violation)

**Location:** `src/Events/EventConsumer.php:194-203`

**Issue:** AMQP topic exchange:
- `*` = "exactly one segment"
- `#` = "zero or more segments"

W kodzie:
```php
$regex = '/^'.str_replace(['\.', '\*', '\#'], ['\.', '[^.]+', '.+'], preg_quote($pattern, '/')).'$/';
```

`'#'` → `'.+'` (jeden lub więcej znaków, ale wymaga co najmniej jednego). To NIE matchuje "zero segments".

**Scenario:** Handler subscribes `crm.#`. Publisher publishuje `crm.entity` (single segment after `crm.`) — oczekiwane że match. Aktualnie nie matchuje.

**Impact:** Subscribers cicho omijają eventy które powinny matchować. Trudno wykrywalne — RabbitMQ binding by zadziałało, ale SDK filter to odrzuci.

**Suggested fix:**
```php
$regex = '/^'.str_replace(['\.', '\*', '\#'], ['\.', '[^.]+', '.*'], preg_quote($pattern, '/')).'$/';
//                                                          ^^^ było `.+`, ma być `.*`
```

Plus test: `matches('crm.contact', 'crm.#')` musi być `true`.

### HI-5 — JsonFormatter to stub bez faktycznej integracji z Monolog

**Location:** `src/Logging/JsonFormatter.php`

**Issue:** Klasa marked w docblocku jako "Sprint 0 scaffold; Full Monolog integration lands in Sprint 0 step 6 once vendor is installed and Monolog version is pinned." Aktualnie:
- Nie rozszerza `Monolog\Formatter\JsonFormatter`
- Nie implementuje `format(LogRecord): string`
- Tylko data holder z `contextFields()` helperem

Konsumencki kod, który deklaruje `JsonFormatter` jako Monolog formatter, zfailuje przy boot Laravel logger.

**Impact:** Faktycznie wartość biznesowa JsonFormatter = zero. README + plan reklamują "JSON na stdout, Loki-friendly" — kontrakt złamany. Loki musi parsować plain text logs zamiast structured JSON.

**Suggested fix:**
- Dokończyć integrację z Monolog 3:
  ```php
  class JsonFormatter extends \Monolog\Formatter\JsonFormatter {
      public function format(LogRecord $record): string {
          $extra = $record->extra;
          $extra[$this->correlationField] = $this->context->current();
          $extra['service'] = $this->serviceName;
          return parent::format($record->with(extra: $extra));
      }
  }
  ```
- Dodać `monolog/monolog` jako explicit `require` w composer.json (chociaż transitive z `illuminate/log`).
- Dokumentacja: jak skonfigurować Laravel `config/logging.php` żeby użył tego formatera.

---

## Medium severity findings (10)

### MD-1 — CorrelationIdMiddleware nie waliduje inbound header (CRLF / log injection)

**Location:** `src/Http/CorrelationIdMiddleware.php:22`

**Issue:**
```php
$correlationId = $request->headers->get(self::HEADER) ?: $this->context->generate();
```

Każda wartość z inbound `X-Correlation-ID` jest akceptowana bez walidacji formatu.

**Scenario:** Attacker wysyła `X-Correlation-ID: legit-uuid\r\nSet-Cookie: admin=1`. Po przejściu przez logger (raw write) lub przy response header echoing → CRLF injection.

**Impact:** Log injection (zafałszowane wpisy w Loki), w skrajnym przypadku HTTP response header splitting.

**Suggested fix:**
```php
const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$inbound = $request->headers->get(self::HEADER);
$correlationId = ($inbound && preg_match(self::UUID_PATTERN, $inbound))
    ? $inbound
    : $this->context->generate();
```

### MD-2 — SchemaDiscovery path traversal via composer extra metadata

**Location:** `src/Events/SchemaDiscovery.php:54-62`

**Issue:**
```php
$dir = $this->vendorPath.'/'.$package['name'].'/'.trim($relative, '/');
```

`$relative` pochodzi z `composer.json` package-u (`extra.abeon.event-schemas`). Brak `realpath()` check — package z `"event-schemas": "../config/"` może czytać pliki spoza vendor.

**Scenario:** Compromised npm dep, malicious internal package, supply chain attack. Reads sensitive files (`.env`, app config) podczas schema discovery.

**Impact:** Info disclosure jeśli plik istnieje i jest JSON-parsowalny. Dependent on trust model dla composer packages (zazwyczaj wysoki, ale nie absolute).

**Suggested fix:**
```php
$packageDir = realpath($this->vendorPath.'/'.$package['name']);
$dir = realpath($packageDir.'/'.trim($relative, '/'));
if ($dir === false || !str_starts_with($dir, $packageDir)) {
    continue;  // path escape attempt — skip
}
```

### MD-3 — UUID generation duplicated w 3 miejscach

**Location:** `src/Logging/CorrelationContext.php:41-48`, `src/Events/EnvelopeBuilder.php:60-67`, `src/Client/ServiceTokenProvider.php:67-73`

**Issue:** Identyczny algorytm UUIDv4 (manual bit-twiddling) w trzech klasach.

**Impact:** DRY violation. Risk inconsistency jeśli ktoś "fix-uje" jeden a nie pozostałe. Nieczyste.

**Suggested fix:** Extract do `Abeon\SDK\Support\Uuid::v4(): string` lub dodać `ramsey/uuid` jako dep (oneliner `Uuid::uuid4()->toString()`). Wszystkie 3 miejsca delegują.

### MD-4 — ServiceClient brak retry config dla 5xx / timeout

**Location:** `src/Client/ServiceClient.php:24-48`

**Issue:** Guzzle używa domyślnego zachowania (no retry, no explicit timeout). Każdy transient 503 / network glitch → propagowany jako `ServiceCallException`. Każde wywołanie service-to-service jest fragile.

**Scenario:** Downstream serwis ma 1s outage przy deploy. Konsument widzi failed request → cascade do user-facing error.

**Suggested fix:**
- Dodać `abeon.client.max_retries` (default 2) + `abeon.client.retry_delay_ms` (default 500ms) w configu.
- W `ServiceClient::service()`:
  ```php
  ->retry(
      $this->config->clientMaxRetries(),
      $this->config->clientRetryDelayMs(),
      fn ($exception, $request) => $exception instanceof ConnectionException
          || ($exception instanceof RequestException && in_array($exception->response?->status(), [502, 503, 504]))
  )
  ->timeout($this->config->clientTimeoutSeconds())  // default 10s
  ```

### MD-5 — ServiceTokenProvider nie flushuje cache na signature-error

**Location:** `src/Client/ServiceTokenProvider.php:24-60`

**Issue:** Token cached in-memory (5min default). Jeśli private key rotuje (deploy z nowym K8s Secret), pod podgrzewa się z nowym kluczem, ale `ServiceTokenProvider` wciąż używa starego tokenu z cache aż do expiry. Auth widzi token podpisany kluczem którego nie ma w JWKS → 401.

**Scenario:** Key rotation deployment window. 5min cascading 401 errors aż wszystkie podsy refreshują.

**Suggested fix:** ServiceClient listener który łapie 401 → wywołuje `ServiceTokenProvider::flush()` → retry once z fresh tokenem. Albo: udokumentować że rotation key wymaga full deployment refresh wszystkich serwisów (worse UX).

### MD-6 — RabbitMqCheck `ping()` bez timeout

**Location:** `src/Health/RabbitMqCheck.php`, `src/Events/RabbitMq.php:65-91`

**Issue:** `AMQPStreamConnection` constructor nie ma explicitnego `connection_timeout`. Default = OS TCP timeout (Linux: 60-130s).

**Scenario:** RabbitMQ network partition (firewall rule, broker down hard). `/health/ready` probe wisi 60s+ → K8s readiness probe failure (default timeout 10s) wykrywa pod jako down dopiero po 30s wisi.

**Impact:** Slow failure detection, K8s wolniej restartuje pod.

**Suggested fix:**
```php
return new AMQPStreamConnection(
    host: $parts['host'],
    port: $parts['port'] ?? 5672,
    // ...
    connection_timeout: 3.0,
    read_write_timeout: 3.0,
);
```

Plus config option `ABEON_RABBITMQ_HEALTH_TIMEOUT` (default 3s) dla probe-y.

### MD-7 — OutboxLagCheck pad gdy tabela nie istnieje

**Location:** `src/Health/OutboxLagCheck.php:34-46`

**Issue:** Query `whereNull('processed_at')->min('created_at')` na `abeon_event_outbox`. Jeśli migration jeszcze nie odpalona (race przy K8s init container) — query rzuca `QueryException` → check zwraca `down`.

**Scenario:** Świeży deployment, init-container z migration startuje, main container też startuje, readiness probe odpala się przed migration. Service marked not-ready mimo poprawnego bootu.

**Suggested fix:**
```php
public function run(): CheckResult
{
    $conn = $this->db->connection($this->config->outboxConnection());
    if (! $conn->getSchemaBuilder()->hasTable(OutboxPublisher::TABLE)) {
        return CheckResult::degraded('Outbox table not yet migrated');
    }
    // ...rest
}
```

### MD-8 — SchemaDiscovery silent `@file_get_contents()` suppression

**Location:** `src/Events/SchemaDiscovery.php:30,68`

**Issue:** Use `@file_get_contents()` z error suppression. Jeśli plik schematu nie da się odczytać (permission, broken symlink) — nigdy się nie dowiedzieć. EventCatalog::all() po prostu pomija missing schemas. Future strict-mode validation będzie failować bez powodu.

**Suggested fix:**
- Remove `@` suppression.
- Log warning gdy plik istnieje ale nie można odczytać.
- Optional: throw w strict mode (configurable).

### MD-9 — Lazy config validation — błąd dopiero przy pierwszym callu

**Location:** `src/Config/AbeonConfig.php` różne metody

**Issue:** `serviceName()`, `serviceJwtPrivateKey()`, `serviceJwtKid()` rzucają `RuntimeException` przy access. Inne (`authUrl()`) zwracają empty string. Inconsistent — boot się udaje, błędy wychodzą losowo w runtime.

**Suggested fix:** Dodać `php artisan abeon:config:validate` command + opcjonalny boot-time check (env var `ABEON_VALIDATE_CONFIG_ON_BOOT=true`). Czyta wszystkie required keys; fail-fast z czytelnym error przy starcie.

### MD-10 — ServiceClient brak `Idempotency-Key` header dla POST/PATCH

**Location:** `src/Client/ServiceClient.php`

**Issue:** Service-to-service POST/PATCH calls. Brak idempotency key. Retry (jeśli zostanie dodany, MD-4) lub Guzzle automatic timeout retry → duplicate side effects po stronie serwera.

**Scenario:** `$client->service('auth')->post('/api/v1/internal/registry/register', ...)` failuje na timeout server-side ale rzeczywiście został wykonany. Caller retries → drugi register call.

**Suggested fix:**
- Auto-generate `Idempotency-Key: <uuid>` per call.
- ServiceClient zapamiętuje per-call key dla retry semantics.
- Server-side: dokumentować że endpoint-y są idempotentne by name (Auth dedupes po service_name) — wtedy header nie jest twardo wymagany ale safety net.

---

## Low severity findings (5)

### LO-1 — RabbitMQ DSN parse nie urldecodes user/pass

**Location:** `src/Events/RabbitMq.php:79-82`

**Issue:** `parse_url($dsn)` zwraca `$parts['user']` / `$parts['pass']` zdekodowane częściowo. Encoded characters (`%40` = `@`, `%23` = `#`) nie są decoded.

**Suggested fix:** `urldecode($parts['user'] ?? 'guest')`, same dla `pass`.

### LO-2 — AbeonException używa HTTP status jako exception code

**Location:** `src/Exceptions/AbeonException.php:13-22`

**Issue:** `parent::__construct($message, $problem->status, $previous)` — mixing HTTP status z exception code semantics. Code `401` w innym kontekście to bezsens.

**Suggested fix:** Fixed code (np. `0`) lub własna enum constants. Status zostaje w `$this->problem->status`.

### LO-3 — EventConsumer double-loop w matchingHandlers + subscriptions

**Location:** `src/Events/EventConsumer.php:176,215`

**Issue:** `matchingHandlers()` i `subscriptions()` iterują wszystkie handlery (tagged services). Dla każdego routing key wykonują pełne O(handlers) skanowanie regexem.

**Impact:** Z 50+ handlerów + duża rate eventów = niedostrzegalne CPU overhead. Real production load: irrelevant.

**Suggested fix:** Cache subscription mapping (`routing_key => [handler, ...]`) at startup, używaj lookup. Optional micro-optimization.

### LO-4 — HealthController brak per-check timeout

**Location:** `src/Health/HealthController.php`

**Issue:** Każdy check odpala się synchronicznie. Jeden hanging (np. RabbitMqCheck przed MD-6 fix) blokuje cały `/health/ready`.

**Suggested fix:** Wrap każdy check w `set_time_limit(3)` lub `pcntl_alarm`. Po fix MD-6 (timeout w RabbitMq::ping) — mniej krytyczne.

### LO-5 — Singletons z mutable state (Octane/Swoole concern)

**Location:** `src/Events/RabbitMq.php` (`$connection`, `$channel`), `src/Client/ServiceTokenProvider.php` (`$cached`)

**Issue:** Singletons trzymają stan między requestami. W Laravel HTTP-FPM = OK (per-process). W Octane/Swoole worker żyje wiele requestów — token cache może być shared = OK; connection do RabbitMQ shared = potentially good (connection pooling).

**Action:** Dokumentacja w class-level docblock: "Octane-compatible — state designed for process lifetime."

---

## Odrzucone findings (overstated lub false-positive)

Część findings agentów została odrzucona po fact-check. Dokumentuję dla transparentności i żeby reviewer widział że review był critical, nie cargo-cult.

### Rejected #1 — "JWT type juggling Critical w decodeUser()"

**Claim:** `(string) $data['org_id']` jest unsafe, attacker może podać dowolny typ.

**Reality:** Po `JWT::decode()` claim payload jest deserializowanym tokenem — wartości pochodzą z trusted source (sygned przez Auth). Strict types w PHP 8.3 dla parametrów konstruktora User DTO. Cast `(string) $data['email']` po `JWT::decode` to nie attack vector.

**Verdict:** Reject.

### Rejected #2 — "Weak JTI entropy w ServiceTokenProvider"

**Claim:** Manual UUID-v4 encoding po `random_bytes(16)` redukuje entropię.

**Reality:** `random_bytes(16)` zwraca 16 cryptographically secure bytes (128 bit entropy). UUIDv4 formatting (bit-twiddling 2 bity dla version/variant + dash separators) nie zmniejsza entropii — tylko formuje wyjście. JTI ma 122 bity rzeczywistej entropii po UUIDv4 encoding. Wzór standardowy, bezpieczny.

**Verdict:** Reject. (MD-3 DRY problem dotyczy duplikacji kodu, nie security.)

### Rejected #3 — "EventConsumer brak schema validation envelope = Critical"

**Claim:** Consumer pownien validować envelope JSON Schema przed dispatch.

**Reality:** Sprint 0 decyzja #6: **loose v1, strict opt-in v0.2**. To świadomy trade-off dla early iteration. Plan SDK explicit. Dodanie strict validation teraz blokuje fast-evolve event shapes.

**Verdict:** Reject — by design.

### Rejected #4 — "Exponential backoff integer overflow w drainer = Critical"

**Claim:** Po ~30 attempts `2 ** 30` przekracza int32, `min(300, ...)` nie chroni przed overflow → infinite loop.

**Reality:** `fetchBatch()` filtruje `where('attempts', '<', outboxMaxAttempts())` (default 5). Wiersz z attempts ≥ 5 NIE jest fetchowany → backoff nigdy nie liczony dla `nextAttempts > 5`. `2 ** 5 = 32`, mieści się w int32. Brak overflow.

**Verdict:** Reject. Pozostaje real concern: wiersze z `attempts ≥ max_attempts` są permanently stuck (HI w correctness audit) — ale to inny bug, addressowany przez sugerowany `php artisan abeon:events:outbox-stuck` command (action item).

### Rejected #5 — "JWT header parsing length unbounded = High"

**Claim:** `base64_decode` + `json_decode` na header bez length limits — DoS via giant `kid`.

**Reality:** `base64_decode` PHP-native funkcja, nie ma path do RCE/DoS via input. `json_decode` z domyślnym depth 512 chroni przed JSON bomb. Memory limit Laravel chroni przed gigantycznym input. Browser/Traefik max header size już ogranicza.

**Verdict:** Reject — overengineered defense.

### Rejected #6 — "Exception messages logging credentials"

**Claim:** `$e->getMessage()` przy RabbitMQ connection failure może leakować DSN z hasłem.

**Reality:** `php-amqplib` exceptions zazwyczaj zwracają `"Connection refused"`, `"Host unreachable"` — nie raw DSN. Defensive concern wart inspekcji ale nie konkretny vulnerability.

**Verdict:** Reject jako Critical, accept jako general best practice (sanityzuj log context przed JsonFormatter).

---

## Strengths (co jest dobrze zrobione)

| Aspekt | Notatka |
|---|---|
| **Layering** | Core / Auth / Events / Client / Services strict — przygotowane pod future subtree split. |
| **Strict types** | `declare(strict_types=1)` w każdym pliku. |
| **Readonly DTOs** | 7 DTOs jako `final readonly class` z immutable properties. |
| **fromArray/toArray symmetry** | Każdy DTO ma symmetric serialization. Łatwe contract testing. |
| **Federation pattern (M2/M3)** | SchemaDiscovery + EventCatalog — service-owned schemas via composer metadata. Nie ma platform-team bottleneck. |
| **Outbox pattern (decyzja #5)** | Atomicity z business transaction — choć wymaga better enforcement (HI-1). |
| **Service-to-service auth** | RS256 per-service key, JWKS local validation, no roundtrip per request. |
| **Idempotency consumer-side** | `abeon_processed_events` table — second-line defense pomimo HI-2/HI-3 issues. |
| **Health checks** | Pluggable `Check` interface, K8s-ready `/health` + `/health/ready` z 200/503. |
| **Correlation ID** | End-to-end propagation HTTP + RabbitMQ + logs (modulo MD-1 validation gap). |
| **ServiceProvider organization** | `register()` / `boot()` separation, scoped vs singleton appropriate, middleware aliases. |
| **Migration design** | Outbox + processed_events; publishable; `enabled by default` per decyzja #5. |
| **ADR documentation** | 5 ADR-ów lockują kontrakty. JWT, envelope, correlation, REST envelope, s2s auth — wszystkie z scenariuszami, consequences, references. |

---

## Test coverage gap (krytyczne)

**Stan:** ZERO testów PHP. Tylko `php -l` (syntax check) walidowany przez Docker.

**Plan v1.4 sekcja E zakłada:**
- Unit tests via PHPUnit/Pest dla logiki SDK
- Integration tests z Testcontainers (RabbitMQ + MariaDB + Redis)
- Contract tests przeciw schemas/fixtures
- Consumer harness w `tests/fake-service/` jako shipping test app
- Static analysis PHPStan level 8

**Reality:** Nic z tego nie zaimplementowane. Sprint 3 plan wymieniał "dokumentacja" jako finał, ale testy są równie wymagane przed SDK 1.0.

**Action:** Sprint 3.5 — dodać przynajmniej:
1. PHPUnit boot (z `composer install`)
2. Unit testy dla:
   - `JwtValidator` (mock JWKS)
   - `RoutingKey::assertValid`
   - `EnvelopeBuilder::build`
   - `ProcessedEvents` (z SQLite in-memory)
   - `AuthContext`, `CorrelationContext`
   - DTO `fromArray/toArray` roundtrip
3. Contract tests przeciw fixtures (parity z `@abeon/shared`)
4. Fake-service skeleton w `tests/fake-service/` weryfikujący ServiceProvider boot

Bez tego SDK 1.0 jest "działa na maszynie autora" — co jest nieakceptowalne dla 16 konsumentów.

---

## Action plan

### Sprint 3.5 — Critical + High fixes + testy (~1 tydzień)

| Task | Priority | Effort |
|---|---|---|
| HI-1 runtime check `DB::transactionLevel()` w OutboxPublisher | High | 0.5d |
| HI-4 wildcard regex `#` → `.*` + test | High | 0.5d |
| HI-3 ProcessedEvents catch tylko `23000` unique constraint | High | 0.5d |
| HI-2 docs: "handlers MUST be idempotent" w ADR-0002 + README | High | 0.5d |
| HI-5 dokończyć JsonFormatter Monolog integration | High | 1d |
| `composer install` + PHPUnit setup + baseline unit tests | Critical | 2d |
| Contract testy przeciw fixtures (parity z TS) | Critical | 1d |
| Fake-service test harness (mini Laravel app w tests/) | Critical | 1d |

**Total:** ~6.5d w Sprint 3.5.

### Sprint 4 — Medium fixes (~3-5 dni rozłożone)

| Task | Priority | Effort |
|---|---|---|
| MD-1 CorrelationIdMiddleware validation UUID format | Medium | 0.5d |
| MD-2 SchemaDiscovery realpath check | Medium | 0.5d |
| MD-3 UUID generation extract do `Support\Uuid` | Medium | 0.25d |
| MD-4 ServiceClient retry config (Guzzle middleware) | Medium | 1d |
| MD-5 ServiceTokenProvider auto-flush on 401 | Medium | 0.5d |
| MD-6 RabbitMq connection timeout config | Medium | 0.25d |
| MD-7 OutboxLagCheck hasTable() guard | Medium | 0.25d |
| MD-8 SchemaDiscovery proper error reporting | Medium | 0.5d |
| MD-9 boot-time config validate command | Medium | 0.5d |
| MD-10 Idempotency-Key auto-injection w ServiceClient | Medium | 0.5d |
| CR-1 OutboxDrainer `lockForUpdate()` | Medium | 0.5d |

**Total:** ~5.5d w Sprint 4.

### Sprint 5 — Low + nice-to-haves

LO-1 do LO-5 + opcjonalne: `abeon:events:outbox-stuck` command, JWKS retry, exception sanitization w log context.

**Total:** ~3-4d, optional w zależności od priorytetów Fazy 1.

---

## Conclusion

SDK jest **architectonicznie solid**, well-documented, follows Laravel conventions. Główne ryzyka są w **validation boundaries** (CRLF, path traversal, schema strictness) i **runtime safety nets** (transaction enforcement, retry semantics, idempotency contract). 

**Production-blocker:** brak testów PHPUnit. Sprint 3.5 powinien je dodać przed SDK 1.0 stable release (lub po świadomej decyzji że Faza 1 jest "tight-loop testing" przez Auth + CRM use).

**Recommendation:** Aplikować Critical + High w Sprint 3.5 (1 tydzień), Medium w Sprint 4 (rozłożone z budową Auth service), Low w Sprint 5+ as time permits. Test coverage = priorytet absolutny.

---

## Załączniki

- Pełne raw outputy 3 review-ów (security, correctness, code quality) zapisane w `/home/mmucha/.claude/projects/-home-mmucha-projects-abeon-suit/ff2e62d7-5df7-4470-bc56-785595d3e569/tool-results/` na potrzeby audit trail.
- Wszystkie pozycje cross-referenced do `file:line` w `abeon-sdk-php/src/`.
- Wcześniejsze dokumenty: `abeon-sdk-phase0-plan.md` (v1.4), `abeon-phase0-summary.md`, ADR-y 0001-0005 w `docs/adr/`.
