<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use PdfBlock\Laravel\Ai\DefaultPrincipalResolver;
use PdfBlock\Laravel\Contracts\AiPrincipalResolver;
use PdfBlock\Laravel\PdfBlockServiceProvider;

/**
 * Rota opcional `GET {path}` que o servidor Node do chat de IA chama, repassando
 * Cookie/Authorization do navegador, para saber quem é a pessoa.
 *
 * A rota nasce DESLIGADA: quem liga (`pdf-block.ai.authorize.enabled`) é o host.
 * Como ela só é registrada no `boot()`, cada teste liga a config ANTES do boot,
 * em `defineEnvironment()` — por isso o estado vai numa propriedade estática,
 * zerada no `setUp()`.
 */
final class AiAuthorizeTest extends TestCase
{
    /** @var array<string, mixed> Config `pdf-block.ai.authorize` aplicada antes do boot. */
    private static array $authorize = [];

    /** Resolver que o "host" registra no container antes do provider do pacote subir. */
    public static ?AiPrincipalResolver $hostResolver = null;

    protected function setUp(): void
    {
        self::$authorize = [];
        self::$hostResolver = null;
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        // O provider do "host" vem ANTES do pacote: o binding dele já existe quando o
        // `register()` do pacote roda, e o `bindIf` não pode sobrescrevê-lo.
        // (O `defineEnvironment` roda depois do register, serviria de pouco aqui.)
        return [AiAuthorizeHostProvider::class, PdfBlockServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Rotas `web` precisam de chave de criptografia (cookies/sessão).
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        foreach (self::$authorize as $key => $value) {
            $app['config']->set("pdf-block.ai.authorize.{$key}", $value);
        }
    }

    /** Resolver de teste que devolve sempre o mesmo principal. */
    private function fixedResolver(array $principal): AiPrincipalResolver
    {
        return new class($principal) implements AiPrincipalResolver {
            /** @param array<string, mixed> $principal */
            public function __construct(private readonly array $principal)
            {
            }

            public function resolve(Request $request): ?array
            {
                return $this->principal;
            }
        };
    }

    /**
     * Liga a rota e sobe a aplicação já com a config aplicada.
     *
     * @param  array<string, mixed>  $authorize
     */
    private function bootWith(array $authorize): void
    {
        self::$authorize = $authorize;
        $this->refreshApplication();
    }

    public function test_desligada_por_padrao_a_rota_nao_existe(): void
    {
        $this->getJson('/pdf-block/ai/authorize')->assertNotFound();
    }

    public function test_config_padrao_nasce_desligada_com_caminho_e_middleware(): void
    {
        $this->assertFalse((bool) config('pdf-block.ai.authorize.enabled'));
        $this->assertSame('pdf-block/ai/authorize', config('pdf-block.ai.authorize.path'));
        $this->assertSame(['web', 'auth'], config('pdf-block.ai.authorize.middleware'));
    }

    public function test_ligada_sem_usuario_devolve_401(): void
    {
        $this->bootWith(['enabled' => true, 'middleware' => ['web']]);

        $response = $this->getJson('/pdf-block/ai/authorize');

        $response->assertStatus(401);
        $this->assertSame(['error' => 'não autenticado'], $response->json());
    }

    public function test_ligada_com_usuario_devolve_200_com_o_id(): void
    {
        $this->bootWith(['enabled' => true, 'middleware' => ['web']]);

        $user = new class extends User {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };

        $response = $this->actingAs($user)->getJson('/pdf-block/ai/authorize');

        $response->assertOk();
        // O id sai sempre como string, mesmo com chave numérica.
        $this->assertSame(['id' => '42'], $response->json());
    }

    public function test_resolver_do_host_no_container_vence_o_padrao(): void
    {
        self::$hostResolver = $this->fixedResolver(['id' => 'host-1', 'tenant' => 'acme']);
        $this->bootWith(['enabled' => true, 'middleware' => ['web']]);

        $this->assertSame(self::$hostResolver, $this->app->make(AiPrincipalResolver::class));
        $this->getJson('/pdf-block/ai/authorize')
            ->assertOk()
            ->assertExactJson(['id' => 'host-1', 'tenant' => 'acme']);
    }

    public function test_sem_binding_do_host_o_padrao_e_o_default_resolver(): void
    {
        $this->bootWith(['enabled' => true, 'middleware' => ['web']]);

        $this->assertInstanceOf(DefaultPrincipalResolver::class, $this->app->make(AiPrincipalResolver::class));
    }

    public function test_middleware_configurado_e_aplicado(): void
    {
        // Sem `auth` na lista, quem barra é só o resolver — aqui ele devolve fixo,
        // então a pessoa sem login passa.
        self::$hostResolver = $this->fixedResolver(['id' => 'fixo']);
        $this->bootWith(['enabled' => true, 'middleware' => ['web']]);

        $this->getJson('/pdf-block/ai/authorize')->assertOk()->assertExactJson(['id' => 'fixo']);
    }

    public function test_middleware_auth_padrao_barra_antes_do_resolver(): void
    {
        // Com o padrão ['web', 'auth'] o pedido sem login nem chega ao resolver
        // fixo. A requisição pede JSON, então o `auth` responde 401.
        self::$hostResolver = $this->fixedResolver(['id' => 'fixo']);
        $this->bootWith(['enabled' => true]);

        $this->getJson('/pdf-block/ai/authorize')->assertStatus(401);
    }

    public function test_middleware_padrao_visitante_sem_json_leva_302_e_com_accept_json_401(): void
    {
        // O que o bin do chat recebe com o middleware PADRÃO ['web', 'auth'] num host
        // de verdade (com tela de login). Sem pedir JSON, o `auth` redireciona — por
        // isso o bin manda `Accept: application/json` e não segue redirecionamento.
        self::$hostResolver = $this->fixedResolver(['id' => 'fixo']);
        $this->bootWith(['enabled' => true]);
        $this->app['router']->get('/login', fn () => 'tela de login')->name('login');
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->get('/pdf-block/ai/authorize')->assertRedirect('/login');

        $response = $this->get('/pdf-block/ai/authorize', ['Accept' => 'application/json']);
        $response->assertStatus(401);
        $this->assertSame(['message' => 'Unauthenticated.'], $response->json());
    }

    public function test_middleware_padrao_com_accept_json_401_mesmo_sem_rota_de_login(): void
    {
        // Host sem rota `login` (só API): pedindo JSON, o `auth` não tenta montar o
        // redirecionamento, então não há o 500 de "Route [login] not defined".
        $this->bootWith(['enabled' => true]);

        $this->get('/pdf-block/ai/authorize', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_caminho_configurado_e_respeitado(): void
    {
        $this->bootWith(['enabled' => true, 'path' => 'x/quem-sou-eu', 'middleware' => ['web']]);

        $this->getJson('/x/quem-sou-eu')->assertStatus(401);
        $this->getJson('/pdf-block/ai/authorize')->assertNotFound();
    }
}

/** Provider de um app hospedeiro que traz o próprio resolver (se o teste definiu um). */
final class AiAuthorizeHostProvider extends ServiceProvider
{
    public function register(): void
    {
        if (AiAuthorizeTest::$hostResolver !== null) {
            $this->app->bind(AiPrincipalResolver::class, fn () => AiAuthorizeTest::$hostResolver);
        }
    }
}
