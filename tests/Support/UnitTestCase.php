<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * Base class for plain unit tests.
 *
 * Does NOT boot a kernel. All DDD classes (Entity, ValueObject, EntitySet)
 * can be constructed via new since the bootstrap kernel is already running, but
 * the reflection helpers here remain the canonical way to set up
 * protected/private state without triggering AfterConstructTrait side-effects.
 *
 * Extend this class for tests that exercise pure domain logic in isolation:
 * entities, value objects, services with no DB/HTTP dependencies.
 *
 * For tests that need a real DI container or want to replace services, extend
 * IntegrationTestCase instead.
 */
abstract class UnitTestCase extends TestCase
{
    /**
     * Creates an instance of $class bypassing its constructor.
     *
     * Use this to instantiate DDD domain objects (Entity, ValueObject, EntitySet)
     * when you need to set protected or private initial state without triggering
     * constructor side-effects.
     *
     * @template T of object
     * @param class-string<T> $class Fully-qualified class name to instantiate.
     * @return T                     The new instance (constructor not called).
     * @throws ReflectionException   If the class does not exist.
     */
    protected static function instantiateWithoutConstructor(string $class): object
    {
        /** @var T $instance */
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        return $instance;
    }

    /**
     * Sets a property value on $object regardless of visibility, including
     * private properties defined on a parent class.
     *
     * Walks the inheritance chain until the property is found so that
     * private properties on parent classes are also reachable.
     *
     * @param object $object   The target object.
     * @param string $property Property name.
     * @param mixed  $value    Value to set.
     * @throws ReflectionException If the property does not exist anywhere in
     *                             the inheritance chain.
     */
    protected static function setPropertyValue(object $object, string $property, mixed $value): void
    {
        $class = new ReflectionClass($object);

        while ($class !== false) {
            if ($class->hasProperty($property)) {
                $prop = $class->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($object, $value);
                return;
            }
            $class = $class->getParentClass();
        }

        throw new ReflectionException(
            sprintf('Property "%s" does not exist on %s or any of its parents.', $property, get_class($object))
        );
    }

    /**
     * Reads a property value from $object regardless of visibility, including
     * private properties defined on a parent class.
     *
     * @param object $object   The target object.
     * @param string $property Property name.
     * @return mixed            The current value of the property.
     * @throws ReflectionException If the property does not exist anywhere in
     *                             the inheritance chain.
     */
    protected static function getPropertyValue(object $object, string $property): mixed
    {
        $class = new ReflectionClass($object);

        while ($class !== false) {
            if ($class->hasProperty($property)) {
                $prop = $class->getProperty($property);
                $prop->setAccessible(true);
                return $prop->getValue($object);
            }
            $class = $class->getParentClass();
        }

        throw new ReflectionException(
            sprintf('Property "%s" does not exist on %s or any of its parents.', $property, get_class($object))
        );
    }

    /**
     * Replaces the instance a `$class::instance()` singleton accessor hands out, so a test can put a mock behind
     * a service that production code reaches statically instead of through DI (`AuthService::instance()` is the
     * common one).
     *
     * The slot is set on whichever class in the chain DECLARES `instance` — `AuthService` declares its own, while
     * `AppService` inherits `DDDService`'s and therefore shares it with every other `DDDService` subclass that does
     * not redeclare. It is static either way, so it outlives the test: a test that sets it MUST undo that in
     * `tearDown()` via {@see resetSingletonInstance()}, or the mock leaks into unrelated tests.
     *
     * @param class-string $class    The class whose `instance()` accessor should answer $instance.
     * @param object|null  $instance The replacement, or null to clear the slot.
     * @throws ReflectionException   If the class has no `instance` property anywhere in its inheritance chain.
     */
    protected static function setSingletonInstance(string $class, ?object $instance): void
    {
        $reflectionClass = new ReflectionClass($class);

        while ($reflectionClass !== false) {
            if ($reflectionClass->hasProperty('instance')) {
                $reflectionProperty = $reflectionClass->getProperty('instance');
                $reflectionProperty->setAccessible(true);
                $reflectionProperty->setValue(null, $instance);
                return;
            }
            $reflectionClass = $reflectionClass->getParentClass();
        }

        throw new ReflectionException(
            sprintf('Class "%s" has no "instance" property in its inheritance chain.', $class)
        );
    }

    /**
     * Clears a singleton slot set by {@see setSingletonInstance()} — belongs in `tearDown()` of every test that
     * set one, because the slot is shared across the whole test run.
     *
     * @param class-string $class  The class whose `instance()` accessor should go back to building its own.
     * @throws ReflectionException If the class has no `instance` property anywhere in its inheritance chain.
     */
    protected static function resetSingletonInstance(string $class): void
    {
        static::setSingletonInstance($class, null);
    }

    /**
     * Invokes a method on $object regardless of visibility.
     *
     * @param object        $object The target object.
     * @param string        $method Method name.
     * @param array<mixed>  $args   Arguments to pass to the method.
     * @return mixed                The return value of the method.
     * @throws ReflectionException  If the method does not exist.
     */
    protected static function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $args);
    }
}
