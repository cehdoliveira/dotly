<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test double usado para verificar dispatch real via rota em formato array
 * ([$objeto, "metodo"]). E a UNICA forma aceita pelo Dispatcher que permite
 * apontar para uma instancia pre-construida: rotas em string "classe:metodo"
 * sempre fazem `new $classe` dentro de exec(), entao nao ha como plugar um
 * double nelas.
 */
final class DispatcherTestTarget
{
    public bool $called = false;
    public array $receivedArgs = [];

    public function handle(array $args): void
    {
        $this->called = true;
        $this->receivedArgs = $args;
    }
}

final class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $_SERVER["REQUEST_URI"] = "/teste";
        // Dispatcher::get_path_info() deriva o path de getenv("REQUEST_URI"),
        // nao de $_SERVER["REQUEST_URI"] nem de PATH_INFO — por isso cada
        // teste que precisa casar uma rota ajusta este env explicitamente
        // antes de instanciar um novo Dispatcher.
        putenv("REQUEST_URI=/teste");

        $this->dispatcher = new Dispatcher(true);
    }

    protected function tearDown(): void
    {
        putenv("REQUEST_URI");
        parent::tearDown();
    }

    public function testConstructorSetsRequestUri(): void
    {
        $this->assertIsString($this->dispatcher->get_request_uri());
    }

    public function testAddRouteStoresValidRoute(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        putenv("REQUEST_URI=/test");

        $d = new Dispatcher(true);
        $target = new DispatcherTestTarget();
        $d->add_route("GET", "/test", [$target, "handle"]);

        $this->assertTrue($d->exec(), "exec() deve casar a rota recem-registrada e retornar true");
        $this->assertTrue($target->called, "o metodo apontado pela rota deve ter sido executado");
    }

    public function testAddRouteRejectsInvalidMethod(): void
    {
        // O Dispatcher so aceita GET e POST — add_route() nao lanca para
        // outros metodos, apenas ignora silenciosamente (rota nunca e
        // armazenada). Prova indireta via API publica: se a rota tivesse
        // sido guardada, uma requisicao PUT no mesmo path casaria e
        // disparar o target; como nao e guardada, exec() retorna false.
        $_SERVER["REQUEST_METHOD"] = "PUT";
        putenv("REQUEST_URI=/put-only");

        $d = new Dispatcher(true);
        $target = new DispatcherTestTarget();
        $d->add_route("PUT", "/put-only", [$target, "handle"]);

        $this->assertFalse($d->exec());
        $this->assertFalse($target->called);
    }

    public function testExecWithNoRoutesReturnsFalse(): void
    {
        $d = new Dispatcher(true);
        $this->assertFalse($d->exec());
    }

    public function testExecMatchesGetRoute(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        putenv("REQUEST_URI=/foo/42");

        $d = new Dispatcher(true);
        $target = new DispatcherTestTarget();
        $d->add_route("GET", "/foo/([0-9]+)", [$target, "handle"]);

        $result = $d->exec();

        $this->assertTrue($result);
        $this->assertTrue($target->called);
        $this->assertSame("42", $target->receivedArgs[1] ?? null, "grupo capturado do regex deve chegar no metodo dispatchado");
    }

    public function testExecDoesNotMatchDifferentHttpMethod(): void
    {
        $_SERVER["REQUEST_METHOD"] = "POST";
        putenv("REQUEST_URI=/only-get");

        $d = new Dispatcher(true);
        $target = new DispatcherTestTarget();
        $d->add_route("GET", "/only-get", [$target, "handle"]);

        $this->assertFalse($d->exec());
        $this->assertFalse($target->called);
    }

    public function testGetRequestUriNormalizesScriptName(): void
    {
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $d = new Dispatcher(true);
        $uri = $d->get_request_uri();
        $this->assertStringNotContainsString("index.php", $uri);
    }
}
