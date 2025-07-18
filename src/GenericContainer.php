<?php

declare(strict_types=1);

namespace Tempest\Container;

use ArrayIterator;
use Closure;
use ReflectionFunction;
use Tempest\Container\Exceptions\DependencyCouldNotBeAutowired;
use Tempest\Container\Exceptions\DependencyCouldNotBeInstantiated;
use Tempest\Container\Exceptions\InvokedCallableWasInvalid;
use Tempest\Container\Exceptions\TaggedDependencyCouldNotBeResolved;
use Tempest\Reflection\ClassReflector;
use Tempest\Reflection\FunctionReflector;
use Tempest\Reflection\MethodReflector;
use Tempest\Reflection\ParameterReflector;
use Tempest\Reflection\TypeReflector;
use Throwable;
use UnitEnum;

final class GenericContainer implements Container
{
    use HasInstance;

    public function __construct(
        /** @var ArrayIterator<array-key, mixed> $definitions */
        private ArrayIterator $definitions = new ArrayIterator(),

        /** @var ArrayIterator<array-key, mixed> $singletons */
        private ArrayIterator $singletons = new ArrayIterator(),

        /** @var ArrayIterator<array-key, class-string> $initializers */
        public ArrayIterator $initializers = new ArrayIterator(),

        /** @var ArrayIterator<array-key, class-string> $dynamicInitializers */
        private ArrayIterator $dynamicInitializers = new ArrayIterator(),
        private ?DependencyChain $chain = null,
    ) {}

    public function setDefinitions(array $definitions): self
    {
        $this->definitions = new ArrayIterator($definitions);

        return $this;
    }

    public function setSingletons(array $singletons): self
    {
        $this->singletons = new ArrayIterator($singletons);

        return $this;
    }

    public function setInitializers(array $initializers): self
    {
        $this->initializers = new ArrayIterator($initializers);

        return $this;
    }

    public function setDynamicInitializers(array $dynamicInitializers): self
    {
        $this->dynamicInitializers = new ArrayIterator($dynamicInitializers);

        return $this;
    }

    public function getDefinitions(): array
    {
        return $this->definitions->getArrayCopy();
    }

    /**
     * Returns all registered singletons. If `$interface` is specified, returns only singletons for that interface.
     */
    public function getSingletons(?string $interface = null): array
    {
        $singletons = $this->singletons->getArrayCopy();

        if (is_null($interface)) {
            return $singletons;
        }

        return array_filter(
            array: $singletons,
            callback: static fn (mixed $_, string $key) => str_starts_with($key, "{$interface}#") || $key === $interface,
            mode: \ARRAY_FILTER_USE_BOTH,
        );
    }

    public function getInitializers(): array
    {
        return $this->initializers->getArrayCopy();
    }

    public function getDynamicInitializers(): array
    {
        return $this->dynamicInitializers->getArrayCopy();
    }

    public function register(string $className, callable $definition): self
    {
        $this->definitions[$className] = $definition;

        return $this;
    }

    public function unregister(string $className, bool $tagged = false): self
    {
        unset($this->definitions[$className], $this->singletons[$className]);

        if ($tagged) {
            $singletons = array_filter(
                array: $this->getSingletons(),
                callback: static fn (mixed $_, string $key) => ! str_starts_with($key, "{$className}#"),
                mode: \ARRAY_FILTER_USE_BOTH,
            );

            $this->setSingletons($singletons);
        }

        return $this;
    }

    public function has(string $className, null|string|UnitEnum $tag = null): bool
    {
        return isset($this->definitions[$className]) || isset($this->singletons[$this->resolveTaggedName($className, $tag)]);
    }

    public function singleton(string $className, mixed $definition, null|string|UnitEnum $tag = null): self
    {
        if ($definition instanceof HasTag) {
            $tag = $definition->tag;
        }

        $className = $this->resolveTaggedName($className, $tag);

        $this->singletons[$className] = $definition;

        return $this;
    }

    public function config(object $config): self
    {
        $this->singleton($config::class, $config);

        foreach (new ClassReflector($config)->getInterfaces() as $interface) {
            $this->singleton($interface->getName(), $config);
        }

        return $this;
    }

    /**
     * @template TClassName of object
     * @param class-string<TClassName> $className
     * @return null|TClassName
     */
    public function get(string $className, null|string|UnitEnum $tag = null, mixed ...$params): ?object
    {
        $this->resolveChain();

        $dependency = $this->resolve(
            className: $className,
            tag: $tag,
            params: $params,
        );

        $this->stopChain();

        return $dependency;
    }

    public function invoke(ClassReflector|MethodReflector|FunctionReflector|callable|string $method, mixed ...$params): mixed
    {
        if ($method instanceof ClassReflector) {
            $method = $method->getMethod('__invoke');
        }

        if ($method instanceof MethodReflector) {
            return $this->invokeMethod($method, ...$params);
        }

        if ($method instanceof FunctionReflector) {
            return $this->invokeFunction($method, ...$params);
        }

        if ($method instanceof Closure) {
            return $this->invokeClosure($method, ...$params);
        }

        if (is_array($method) && count($method) === 2) {
            return $this->invokeClosure($method(...), ...$params);
        }

        if (method_exists($method, '__invoke')) {
            return $this->invokeClosure(
                $this->get($method)->__invoke(...),
                ...$params,
            );
        }

        throw new InvokedCallableWasInvalid(new Dependency($method));
    }

    private function invokeClosure(Closure $closure, mixed ...$params): mixed
    {
        $this->resolveChain();

        $parameters = $this->autowireDependencies(
            method: $reflector = new FunctionReflector($closure),
            parameters: $params,
        );

        $this->stopChain();

        return $reflector->invokeArgs($parameters);
    }

    private function invokeMethod(MethodReflector $method, mixed ...$params): mixed
    {
        $this->resolveChain();

        $object = $this->get($method->getDeclaringClass()->getName());

        $parameters = $this->autowireDependencies($method, $params);

        $this->stopChain();

        return $method->invokeArgs($object, $parameters);
    }

    private function invokeFunction(FunctionReflector|Closure $callback, mixed ...$params): mixed
    {
        $this->resolveChain();

        $reflector = match (true) {
            $callback instanceof FunctionReflector => $callback,
            default => new ReflectionFunction($callback),
        };

        $parameters = $this->autowireDependencies($reflector, $params);

        $this->stopChain();

        return $reflector->invokeArgs($parameters);
    }

    public function addInitializer(ClassReflector|string $initializerClass): Container
    {
        if (! ($initializerClass instanceof ClassReflector)) {
            $initializerClass = new ClassReflector($initializerClass);
        }

        // First, we check whether this is a DynamicInitializer,
        // which don't have a one-to-one mapping
        if ($initializerClass->getType()->matches(DynamicInitializer::class)) {
            $this->dynamicInitializers[] = $initializerClass->getName();

            return $this;
        }

        $initializeMethod = $initializerClass->getMethod('initialize');

        // We resolve the optional Tag attribute from this initializer class
        $singleton = $initializeMethod->getAttribute(Singleton::class);

        // For normal Initializers, we'll use the return type
        // to determine which dependency they resolve
        $returnType = $initializeMethod->getReturnType();

        foreach ($returnType->split() as $type) {
            $this->initializers[$this->resolveTaggedName($type->getName(), $singleton?->tag)] = $initializerClass->getName();
        }

        return $this;
    }

    public function removeInitializer(ClassReflector|string $initializerClass): Container
    {
        if (! ($initializerClass instanceof ClassReflector)) {
            $initializerClass = new ClassReflector($initializerClass);
        }

        if ($initializerClass->getType()->matches(DynamicInitializer::class)) {
            $index = array_find_key(
                array: $this->dynamicInitializers->getArrayCopy(),
                callback: static fn (string $className) => $className === $initializerClass->getName(),
            );

            unset($this->dynamicInitializers[$index]);

            return $this;
        }

        unset($this->initializers[$initializerClass->getName()]);

        return $this;
    }

    private function resolve(string $className, null|string|UnitEnum $tag = null, mixed ...$params): ?object
    {
        $class = new ClassReflector($className);

        $dependencyName = $this->resolveTaggedName($className, $tag);

        // Check if the class has been registered as a singleton.
        if ($instance = $this->singletons[$dependencyName] ?? null) {
            if ($instance instanceof Closure) {
                $instance = $instance($this);
                $this->singletons[$className] = $instance;
            }

            $this->resolveChain()->add($class);

            return $instance;
        }

        // Check if a callable has been registered to resolve this class.
        if ($definition = $this->definitions[$dependencyName] ?? null) {
            $this->resolveChain()->add(new FunctionReflector($definition));

            return $definition($this);
        }

        // Next we check if any of our default initializers can initialize this class.
        if (($initializer = $this->initializerForClass($class, $tag)) !== null) {
            $initializerClass = new ClassReflector($initializer);

            $this->resolveChain()->add($initializerClass);

            $object = match (true) {
                $initializer instanceof Initializer => $initializer->initialize($this->clone()),
                $initializer instanceof DynamicInitializer => $initializer->initialize($class, $tag, $this->clone()),
            };

            $singleton = $initializerClass->getAttribute(Singleton::class) ?? $initializerClass->getMethod('initialize')->getAttribute(Singleton::class);

            if ($singleton !== null) {
                $this->singleton($className, $object, $tag);
            }

            return $object;
        }

        // If we're requesting a tagged dependency and haven't resolved it at this point, something's wrong
        if ($tag) {
            throw new TaggedDependencyCouldNotBeResolved($this->chain, new Dependency($className), $tag);
        }

        // Finally, autowire the class.
        return $this->autowire($className, ...$params);
    }

    private function initializerForBuiltin(TypeReflector $target, string $tag): ?Initializer
    {
        if ($initializerClass = $this->initializers[$this->resolveTaggedName($target->getName(), $tag)] ?? null) {
            return $this->resolve($initializerClass);
        }

        return null;
    }

    private function initializerForClass(ClassReflector $target, null|string|UnitEnum $tag = null): null|Initializer|DynamicInitializer
    {
        // Initializers themselves can't be initialized,
        // otherwise you'd end up with infinite loops
        if ($target->getType()->matches(Initializer::class) || $target->getType()->matches(DynamicInitializer::class)) {
            return null;
        }

        if ($initializerClass = $this->initializers[$this->resolveTaggedName($target->getName(), $tag)] ?? null) {
            return $this->resolve($initializerClass);
        }

        // Loop through the registered initializers to see if
        // we have something to handle this class.
        foreach ($this->dynamicInitializers as $initializerClass) {
            /** @var DynamicInitializer $initializer */
            $initializer = $this->resolve($initializerClass);

            if (! $initializer->canInitialize(class: $target, tag: $tag)) {
                continue;
            }

            return $initializer;
        }

        return null;
    }

    private function autowire(string $className, mixed ...$params): object
    {
        $classReflector = new ClassReflector($className);

        $constructor = $classReflector->getConstructor();

        if (! $classReflector->isInstantiable()) {
            throw new DependencyCouldNotBeInstantiated($classReflector, $this->chain);
        }

        $instance = $constructor === null
            ? // If there isn't a constructor, don't waste time
            // trying to build it.
            $classReflector->newInstanceWithoutConstructor()
            : // Otherwise, use our autowireDependencies helper to automagically
            // build up each parameter.
            $classReflector->newInstanceArgs(
                $this->autowireDependencies($constructor, $params),
            );

        if (
            ! $classReflector->getType()->matches(Initializer::class) &&
                ! $classReflector->getType()->matches(DynamicInitializer::class) &&
                $classReflector->hasAttribute(Singleton::class)
        ) {
            $this->singleton($className, $instance);
        }

        foreach ($classReflector->getProperties() as $property) {
            if ($property->hasAttribute(Inject::class) && ! $property->isInitialized($instance)) {
                if ($property->hasAttribute(Proxy::class)) {
                    $property->set($instance, $property->getType()->asClass()->getReflection()->newLazyProxy(
                        fn () => $this->get($property->getType()->getName()),
                    ));
                } else {
                    $property->set($instance, $this->get($property->getType()->getName()));
                }
            }
        }

        return $instance;
    }

    /**
     * @return ParameterReflector[]
     */
    private function autowireDependencies(MethodReflector|FunctionReflector $method, array $parameters = []): array
    {
        $this->resolveChain()->add($method);

        $dependencies = [];

        // Build the class by iterating through its
        // dependencies and resolving them.
        foreach ($method->getParameters() as $parameter) {
            $dependencies[] = $this->clone()->autowireDependency(
                parameter: $parameter,
                tag: $parameter->getAttribute(Tag::class)?->name,
                providedValue: $parameters[$parameter->getName()] ?? null,
            );
        }

        return $dependencies;
    }

    private function autowireDependency(ParameterReflector $parameter, null|string|UnitEnum $tag, mixed $providedValue = null): mixed
    {
        $parameterType = $parameter->getType();

        // If the parameter is a built-in type, skip reflection and attempt to provide the value by
        // tagged initializer, a default value or null value.
        if ($parameterType->isBuiltin()) {
            return $this->autowireBuiltinDependency($parameter, $providedValue);
        }

        // Support lazy initialization
        $lazy = $parameter->hasAttribute(Proxy::class);
        // Loop through each type until we hit a match.
        foreach ($parameter->getType()->split() as $type) {
            try {
                return $this->autowireObjectDependency(
                    type: $type,
                    tag: $tag,
                    providedValue: $providedValue,
                    lazy: $lazy,
                );
            } catch (Throwable $throwable) {
                // We were unable to resolve the dependency for the last union
                // type, so we are moving on to the next one. We hang onto
                // the exception in case it is a circular reference.
                $lastThrowable = $throwable;
            }
        }

        // If the dependency has a default value, we do our best to prevent
        // an error by using that.
        if ($parameter->hasDefaultValue()) {
            return $parameter->getDefaultValue();
        }

        // At this point, there is nothing else we can do; we don't know
        // how to autowire this dependency.
        throw $lastThrowable ?? new DependencyCouldNotBeAutowired($this->chain, new Dependency($parameter));
    }

    private function autowireObjectDependency(TypeReflector $type, null|string|UnitEnum $tag, mixed $providedValue, bool $lazy): mixed
    {
        // If the provided value is of the right type,
        // don't waste time autowiring, return it!
        if ($type->accepts($providedValue)) {
            return $providedValue;
        }

        if ($lazy) {
            return $type
                ->asClass()
                ->getReflection()
                ->newLazyProxy(function () use ($type, $tag) {
                    return $this->resolve(className: $type->getName(), tag: $tag);
                });
        }

        // If we can successfully retrieve an instance
        // of the necessary dependency, return it.
        return $this->resolve(className: $type->getName(), tag: $tag);
    }

    private function autowireBuiltinDependency(ParameterReflector $parameter, mixed $providedValue): mixed
    {
        $typeName = $parameter->getType()->getName();
        $tag = $parameter->getAttribute(Tag::class);

        if ($tag !== null && ($initializer = $this->initializerForBuiltin($parameter->getType(), $tag->name))) {
            $initializerClass = new ClassReflector($initializer);

            $object = $initializer->initialize($this->clone());

            $singleton = $initializerClass->getAttribute(Singleton::class) ?? $initializerClass->getMethod('initialize')->getAttribute(Singleton::class);

            if ($singleton !== null) {
                $this->singleton($typeName, $object, $tag->name);
            }

            return $object;
        }

        // Due to type coercion, the provided value may (or may not) work.
        // Here we give up trying to do type work for people. If they
        // didn't provide the right type, that's on them.
        if ($providedValue !== null) {
            return $providedValue;
        }

        // If the dependency has a default value, we might as well
        // use that at this point.
        if ($parameter->hasDefaultValue()) {
            return $parameter->getDefaultValue();
        }

        // If the dependency's type is an array or variadic variable, we'll
        // try to prevent an error by returning an empty array.
        if ($parameter->isVariadic() || $parameter->isIterable()) {
            return [];
        }

        // If the dependency's type allows null or is optional, we'll
        // try to prevent an error by returning null.
        if (! $parameter->isRequired()) {
            return null;
        }

        // At this point, there is nothing else we can do; we don't know
        // how to autowire this dependency.
        throw new DependencyCouldNotBeAutowired($this->chain, new Dependency($parameter));
    }

    private function clone(): self
    {
        return clone $this;
    }

    private function resolveChain(): DependencyChain
    {
        if ($this->chain === null) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

            $this->chain = new DependencyChain($trace[1]['file'] . ':' . $trace[1]['line']);
        }

        return $this->chain;
    }

    private function stopChain(): void
    {
        $this->chain = null;
    }

    public function __clone(): void
    {
        $this->chain = $this->chain?->clone();
    }

    private function resolveTaggedName(string $className, null|string|UnitEnum $tag): string
    {
        if ($tag instanceof UnitEnum) {
            $tag = $tag->name;
        }

        return $tag
            ? "{$className}#{$tag}"
            : $className;
    }
}
