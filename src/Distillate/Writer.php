<?php

namespace com\github\gooh\InterfaceDistiller\Distillate;

class Writer
{
    /**
     * Characters used for indentation
     */
    const INDENT = '    ';

    /**
     * @var \SplFileObject
     */
    protected $fileObject;

    /**
     * @var bool
     */
    protected $inGlobalNamespace;

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
            $distillate->getExtendingInterfaces()
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
     * @return void
     */
    protected function writeInterfaceSignature($interfaceName, $extendingInterfaces = '')
    {
        $nameParts = explode('\\', $interfaceName);
        $interfaceShortName = array_pop($nameParts);
        if ($nameParts) {
            $this->writeString(PHP_EOL);
            $this->writeString('namespace ' . implode('\\', $nameParts) . ';' . PHP_EOL);
            $this->inGlobalNamespace = false;
        } else {
            $this->inGlobalNamespace = true;
        }

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
                static::INDENT . 'public%sfunction %s(%s);',
                $method->isStatic() ? ' static ' : ' ',
                $method->name,
                $this->methodParametersToString($method)
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
     * @param \ReflectionType $type
     * @return string
     */
    protected function resolveType(\ReflectionType $type): string
    {
        /** @var string */
        $fullyQualifiedClassName = $type->getName();

        if ($type->isBuiltin() || $fullyQualifiedClassName === 'self') {
            return $fullyQualifiedClassName;
        }

        $classPrefix = $this->inGlobalNamespace ? '' : '\\';

        return $classPrefix . $fullyQualifiedClassName;
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
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function handleOptionalParameterWithUnresolvableDefaultValue(\ReflectionParameter $parameter)
    {
        return $parameter->allowsNull() ? ' = NULL ' : ' /* = unresolvable */ ';
    }
}
