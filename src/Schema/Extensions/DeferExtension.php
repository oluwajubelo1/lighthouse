<?php

namespace Nuwave\Lighthouse\Schema\Extensions;

use Closure;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Symfony\Component\HttpFoundation\Response;
use Nuwave\Lighthouse\Schema\AST\PartialParser;
use Nuwave\Lighthouse\Support\Contracts\CanStreamResponse;

class DeferExtension extends GraphQLExtension
{
    /**
     * @var \Nuwave\Lighthouse\Support\Contracts\CanStreamResponse
     */
    protected $stream;

    /**
     * @var \Nuwave\Lighthouse\Schema\Extensions\ExtensionRequest
     */
    protected $request;

    /**
     * @var mixed[]
     */
    protected $data = [];

    /**
     * @var mixed[]
     */
    protected $deferred = [];

    /**
     * @var mixed[]
     */
    protected $resolved = [];

    /**
     * @var bool
     */
    protected $defer = true;

    /**
     * @var bool
     */
    protected $streaming = false;

    /**
     * @var int
     */
    protected $maxExecutionTime = 0;

    /**
     * @var int
     */
    protected $maxNestedFields = 0;

    /**
     * @param  \Nuwave\Lighthouse\Support\Contracts\CanStreamResponse  $stream
     * @return void
     */
    public function __construct(CanStreamResponse $stream)
    {
        $this->stream = $stream;
        $this->maxNestedFields = config('lighthouse.defer.max_nested_fields', 0);
    }

    /**
     * The extension name controls under which key
     * the extensions shows up in the result.
     *
     * @return string
     */
    public static function name(): string
    {
        return 'defer';
    }

    /**
     * Handle request start.
     *
     * @param  \Nuwave\Lighthouse\Schema\Extensions\ExtensionRequest  $request
     * @return void
     */
    public function requestDidStart(ExtensionRequest $request): void
    {
        $this->request = $request;
    }

    /**
     * Set the tracing directive on all fields of the query to enable tracing them.
     *
     * @param  \Nuwave\Lighthouse\Schema\AST\DocumentAST  $documentAST
     * @return \Nuwave\Lighthouse\Schema\AST\DocumentAST
     */
    public function manipulateSchema(DocumentAST $documentAST): DocumentAST
    {
        $documentAST = ASTHelper::attachDirectiveToObjectTypeFields(
            $documentAST,
            PartialParser::directive('@deferrable')
        );

        $documentAST->setDefinition(
            PartialParser::directiveDefinition('directive @defer(if: Boolean) on FIELD')
        );

        return $documentAST;
    }

    /**
     * Format extension output.
     *
     * @return mixed[]
     */
    public function jsonSerialize(): array
    {
        return [];
    }

    /**
     * @return bool
     */
    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * Register deferred field.
     *
     * @param  \Closure  $resolver
     * @param  string  $path
     * @return mixed
     */
    public function defer(Closure $resolver, string $path)
    {
        if ($data = Arr::get($this->data, "data.{$path}")) {
            return $data;
        }

        if ($this->isDeferred($path) || ! $this->defer) {
            return $this->resolve($resolver, $path);
        }

        $this->deferred[$path] = $resolver;
    }

    /**
     * @param  \Closure  $originalResolver
     * @param  string  $path
     * @return mixed
     */
    public function findOrResolve(Closure $originalResolver, string $path)
    {
        if (! $this->hasData($path)) {
            if (isset($this->deferred[$path])) {
                unset($this->deferred[$path]);
            }

            return $this->resolve($originalResolver, $path);
        }

        return Arr::get($this->data, "data.{$path}");
    }

    /**
     * Resolve field with data or resolver.
     *
     * @param  \Closure  $originalResolver
     * @param  string  $path
     * @return mixed
     */
    public function resolve(Closure $originalResolver, string $path)
    {
        $isDeferred = $this->isDeferred($path);
        $resolver = $isDeferred
            ? $this->deferred[$path]
            : $originalResolver;

        if ($isDeferred) {
            $this->resolved[] = $path;

            unset($this->deferred[$path]);
        }

        return $resolver();
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function isDeferred(string $path): bool
    {
        return isset($this->deferred[$path]);
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function hasData(string $path): bool
    {
        return Arr::has($this->data, "data.{$path}");
    }

    /**
     * @param  mixed[]  $data
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function response(array $data): Response
    {
        if (empty($this->deferred)) {
            return response($data);
        }

        return response()->stream(
            function () use ($data): void {
                $nested = 1;
                $this->data = $data;
                $this->streaming = true;
                $this->stream->stream($data, [], empty($this->deferred));

                if ($executionTime = config('lighthouse.defer.max_execution_ms', 0)) {
                    $this->maxExecutionTime = microtime(true) + ($executionTime * 1000);
                }

                // TODO: Allow nested_levels to be set in config
                // to break out of loop early.
                while (
                    count($this->deferred) &&
                    ! $this->executionTimeExpired() &&
                    ! $this->maxNestedFieldsResolved($nested)
                ) {
                    $nested++;
                    $this->executeDeferred();
                }

                // We've hit the max execution time or max nested levels of deferred fields.
                // Process remaining deferred fields.
                if (count($this->deferred)) {
                    $this->defer = false;
                    $this->executeDeferred();
                }
            },
            200,
            [
                // TODO: Allow headers to be set in config
                'X-Accel-Buffering' => 'no',
                'Content-Type' => 'multipart/mixed; boundary="-"',
            ]
        );
    }

    /**
     * @param  int  $time
     * @return void
     */
    public function setMaxExecutionTime(int $time): void
    {
        $this->maxExecutionTime = $time;
    }

    /**
     * Override max nested fields.
     *
     * @param  int  $max
     * @return void
     */
    public function setMaxNestedFields(int $max): void
    {
        $this->maxNestedFields = $max;
    }

    /**
     * Check if the maximum execution time has expired.
     *
     * @return bool
     */
    protected function executionTimeExpired(): bool
    {
        if ($this->maxExecutionTime === 0) {
            return false;
        }

        return $this->maxExecutionTime <= microtime(true);
    }

    /**
     * Check if the maximum number of nested field has been resolved.
     *
     * @param  int  $nested
     * @return bool
     */
    protected function maxNestedFieldsResolved(int $nested): bool
    {
        if ($this->maxNestedFields === 0) {
            return false;
        }

        return $nested >= $this->maxNestedFields;
    }

    /**
     * Execute deferred fields.
     *
     * @return void
     */
    protected function executeDeferred(): void
    {
        // TODO: Properly parse variables array
        // TODO: Get debug setting
        $this->data = graphql()
            ->executeQuery(
                $this->request->request()->input('query', ''),
                $this->request->context(),
                $this->request->request()->input('variables', [])
            )
            ->toArray(config('lighthouse.debug'));

        $this->stream->stream(
            $this->data,
            $this->resolved,
            empty($this->deferred)
        );

        $this->resolved = [];
    }
}
