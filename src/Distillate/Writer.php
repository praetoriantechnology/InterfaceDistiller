<?php

namespace com\github\gooh\InterfaceDistiller\Distillate;

class Writer
{
    /**
     * Characters used for indentation
     */
    const INDENT = '    ';

    private const ALIASES_FOR_CLASS_TYPE = [
        'self',
        'parent',
    ];

    /**
     * @var \SplFileObject
     */
    protected $fileObject;

    /**
     * @var bool
     */
    protected $inGlobalNamespace;

    protected string $interfaceNamespace;

    /**
     * @param \SplFileObject $fileObject
     */
    public function __construct(\SplFileObject $fileObject)
    {
        $this->fileObject = $fileObject;
    }

    /**
     * @param Accessors $distillate
     * @return void
     */
    public function writeToFile(Accessors $distillate)
    {
        $this->writeString('<?php' . PHP_EOL);
        $this->writeInterfaceSignature(
            $distillate->getInterfaceName(),
            $distillate->getExtendingInterfaces(),
            $distillate->getInterfaceMethods()
        );
        $this->writeString('{' . PHP_EOL);
        $this->writeMethods($distillate->getInterfaceMethods());
        $this->writeString('}');
    }

    /**
     * @param string $string
     * @return void
     */
    protected function writeString($string)
    {
        $this->fileObject->fwrite($string);
    }

    /**
     * @param string $interfaceName
     * @param string $extendingInterfaces
     * @param array $methods
     * @return void
     */
    protected function writeInterfaceSignature($interfaceName, $extendingInterfaces, array $methods)
    {
        $nameParts = explode('\\', $interfaceName);
        $interfaceShortName = array_pop($nameParts);
        if ($nameParts) {
            $this->writeString(PHP_EOL);
            $this->interfaceNamespace = implode('\\', $nameParts);
            $this->writeString('namespace ' . $this->interfaceNamespace . ';' . PHP_EOL);
            $this->inGlobalNamespace = false;
        } else {
            $this->inGlobalNamespace = true;
        }

        $this->writeUseStatements($methods);

        $this->writeString(PHP_EOL);
        $this->writeString("interface $interfaceShortName");
        if ($extendingInterfaces) {
            $this->writeString(" extends $extendingInterfaces");
        }
        $this->writeString(PHP_EOL);
    }

    /**
     * @param array $methods
     */
    protected function writeMethods(array $methods)
    {
        foreach ($methods as $method) {
            $this->writeDocCommentOfMethod($method);
            $this->writeMethod($method);
            $this->writeString(PHP_EOL);
        }
    }

    /**
     * @param \ReflectionMethod $method
     * @return void
     */
    protected function writeMethod(\ReflectionMethod $method)
    {
        $this->writeString(
            sprintf(
                static::INDENT . 'public%sfunction %s(%s)%s;',
                $method->isStatic() ? ' static ' : ' ',
                $method->name,
                $this->methodParametersToString($method),
                $this->methodReturnTypeToString($method)
            )
        );
        $this->writeString(PHP_EOL);
    }

    /**
     * @param \ReflectionMethod $method
     * @return void
     */
    protected function writeDocCommentOfMethod(\ReflectionMethod $method)
    {
        if ($method->getDocComment()) {
            $this->writeString(static::INDENT);
            $this->writeString($method->getDocComment());
            $this->writeString(PHP_EOL);
        }
    }

    /**
     * @param \ReflectionMethod $method
     * @return string
     */
    protected function methodParametersToString(\ReflectionMethod $method)
    {
        return implode(
            ', ',
            array_map(
                array($this, 'parameterToString'),
                $method->getParameters()
            )
        );
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function parameterToString(\ReflectionParameter $parameter)
    {
        return trim(
            sprintf(
                '%s %s$%s%s',
                $this->methodParameterTypeToString($parameter),
                $parameter->isPassedByReference() ? '&' : '',
                $parameter->name,
                $this->resolveDefaultValue($parameter)
            )
        );
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function methodParameterTypeToString(\ReflectionParameter $parameter): string
    {
        /** @var \ReflectionType|null */
        $parameterType = $parameter->getType();

        //parameter type is not declared
        if ($parameterType === null) {
            return '';
        }

        return sprintf(
            '%s%s',
            $parameterType->allowsNull() ? '?' : '',
            $this->resolveType($parameterType)
        );
    }

    /**
     * @param \ReflectionMethod $method
     * @return string
     */
    protected function methodReturnTypeToString(\ReflectionMethod $method): string
    {
        /** @var \ReflectionType|null */
        $returnType = $method->getReturnType();

        //return type is not declared
        if ($returnType === null) {
            return '';
        }

        return sprintf(': %s%s',
            $returnType->allowsNull() ? '?' : '',
            $this->resolveType($returnType)
        );
    }

    /**
     * @param \ReflectionType $type
     * @return string
     */
    protected function resolveType(\ReflectionType $type): string
    {
        /** @var string */
        $fullyQualifiedClassName = $type->getName();

        if ($type->isBuiltin() || in_array($fullyQualifiedClassName, self::ALIASES_FOR_CLASS_TYPE)) {
            return $fullyQualifiedClassName;
        }

        return (new \ReflectionClass($fullyQualifiedClassName))->getShortName();
    }

    /**
     * @param \ReflectionType $type
     * @return string|null
     */
    protected function createUseStatementForType(\ReflectionType $type): ?string
    {
        /** @var string */
        $fullyQualifiedClassName = $type->getName();

        if ($type->isBuiltin() || in_array($fullyQualifiedClassName, self::ALIASES_FOR_CLASS_TYPE)) {
            return null;
        }

        $reflectionClass = new \ReflectionClass($fullyQualifiedClassName);

        if (!$this->inGlobalNamespace && $reflectionClass->getNamespaceName() === $this->interfaceNamespace) {
            return null;
        }

        if ($this->inGlobalNamespace && !$reflectionClass->inNamespace()) {
            return null;
        }

        return 'use ' . $reflectionClass->getName() . ';';
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function resolveDefaultValue(\ReflectionParameter $parameter)
    {
        if (false === $parameter->isOptional()) {
            return '';
        }

        if ($parameter->isDefaultValueAvailable()) {
            $defaultValue = var_export($parameter->getDefaultValue(), true);
            return ' = ' . preg_replace('(\s)', '', $defaultValue);
        }

        return $this->handleOptionalParameterWithUnresolvableDefaultValue($parameter);
    }

    /**
     * @param array $methods
     * @return void
     */
    protected function writeUseStatements(array $methods): void
    {
        $statements = $this->createUseStatements($methods);

        if (count($statements) === 0) {
            return;
        }

        $this->writeString(PHP_EOL);

        /** @var string $useStatement */
        foreach ($statements as $useStatement) {
            $this->writeString($useStatement . PHP_EOL);
        }
    }

    /**
     * @param array $methods
     * @return array
     */
    protected function createUseStatements(array $methods): array
    {
        $useStatements = [];

        /** @var \ReflectionType $type */
        foreach ($this->getTypesUsedInAllMethods($methods) as $type) {
            $useStatement = $this->createUseStatementForType($type);

            if ($useStatement === null) {
                continue;
            }

            $useStatements[] = $useStatement;
        }

        return $useStatements;
    }

    /**
     * @param array $methods
     * @return array
     */
    protected function getTypesUsedInAllMethods(array $methods): array
    {
        $types = [];

        /** @var \ReflectionMethod $method */
        foreach ($methods as $method) {
            $types = array_merge($types, $this->getTypesUsedInMethodDeclaration($method));
        }

        $typeNames = array_map(function (\ReflectionType $type) {
            return $type->getName();
        }, $types);

        $uniqueNames = array_unique($typeNames);

        return array_values(array_intersect_key($types, $uniqueNames));
    }

    /**
     * @param \ReflectionMethod $method
     * @return array
     */
    protected function getTypesUsedInMethodDeclaration(\ReflectionMethod $method): array
    {
        $types = array_map(function (\ReflectionParameter $parameter) {
            return $parameter->getType();
        }, $method->getParameters());

        $types[] = $method->getReturnType();

        $types = array_filter($types, function (?\ReflectionType $type) {
            return $type !== null;
        });

        return $types;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function handleOptionalParameterWithUnresolvableDefaultValue(\ReflectionParameter $parameter)
    {
        return $parameter->allowsNull() ? ' = NULL ' : ' /* = unresolvable */ ';
    }
}
