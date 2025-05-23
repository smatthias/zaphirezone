<?php

declare (strict_types=1);
namespace Rector\NodeManipulator;

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Type;
use Rector\Enum\ObjectReference;
use Rector\NodeAnalyzer\PropertyPresenceChecker;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\Php\PhpVersionProvider;
use Rector\PhpParser\Node\NodeFactory;
use Rector\PostRector\ValueObject\PropertyMetadata;
use Rector\Reflection\ReflectionResolver;
use Rector\TypeDeclaration\NodeAnalyzer\AutowiredClassMethodOrPropertyAnalyzer;
use Rector\ValueObject\MethodName;
use Rector\ValueObject\PhpVersionFeature;
/**
 * @see \Rector\Tests\NodeManipulator\ClassDependencyManipulatorTest
 */
final class ClassDependencyManipulator
{
    /**
     * @readonly
     * @var \Rector\NodeManipulator\ClassInsertManipulator
     */
    private $classInsertManipulator;
    /**
     * @readonly
     * @var \Rector\NodeManipulator\ClassMethodAssignManipulator
     */
    private $classMethodAssignManipulator;
    /**
     * @readonly
     * @var \Rector\PhpParser\Node\NodeFactory
     */
    private $nodeFactory;
    /**
     * @readonly
     * @var \Rector\NodeManipulator\StmtsManipulator
     */
    private $stmtsManipulator;
    /**
     * @readonly
     * @var \Rector\Php\PhpVersionProvider
     */
    private $phpVersionProvider;
    /**
     * @readonly
     * @var \Rector\NodeAnalyzer\PropertyPresenceChecker
     */
    private $propertyPresenceChecker;
    /**
     * @readonly
     * @var \Rector\NodeNameResolver\NodeNameResolver
     */
    private $nodeNameResolver;
    /**
     * @readonly
     * @var \Rector\TypeDeclaration\NodeAnalyzer\AutowiredClassMethodOrPropertyAnalyzer
     */
    private $autowiredClassMethodOrPropertyAnalyzer;
    /**
     * @readonly
     * @var \Rector\Reflection\ReflectionResolver
     */
    private $reflectionResolver;
    public function __construct(\Rector\NodeManipulator\ClassInsertManipulator $classInsertManipulator, \Rector\NodeManipulator\ClassMethodAssignManipulator $classMethodAssignManipulator, NodeFactory $nodeFactory, \Rector\NodeManipulator\StmtsManipulator $stmtsManipulator, PhpVersionProvider $phpVersionProvider, PropertyPresenceChecker $propertyPresenceChecker, NodeNameResolver $nodeNameResolver, AutowiredClassMethodOrPropertyAnalyzer $autowiredClassMethodOrPropertyAnalyzer, ReflectionResolver $reflectionResolver)
    {
        $this->classInsertManipulator = $classInsertManipulator;
        $this->classMethodAssignManipulator = $classMethodAssignManipulator;
        $this->nodeFactory = $nodeFactory;
        $this->stmtsManipulator = $stmtsManipulator;
        $this->phpVersionProvider = $phpVersionProvider;
        $this->propertyPresenceChecker = $propertyPresenceChecker;
        $this->nodeNameResolver = $nodeNameResolver;
        $this->autowiredClassMethodOrPropertyAnalyzer = $autowiredClassMethodOrPropertyAnalyzer;
        $this->reflectionResolver = $reflectionResolver;
    }
    public function addConstructorDependency(Class_ $class, PropertyMetadata $propertyMetadata) : void
    {
        if ($this->hasClassPropertyAndDependency($class, $propertyMetadata)) {
            return;
        }
        if (!$this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::PROPERTY_PROMOTION)) {
            $this->classInsertManipulator->addPropertyToClass($class, $propertyMetadata->getName(), $propertyMetadata->getType());
        }
        if ($this->shouldAddPromotedProperty($class, $propertyMetadata)) {
            $this->addPromotedProperty($class, $propertyMetadata);
        } else {
            $assign = $this->nodeFactory->createPropertyAssignment($propertyMetadata->getName());
            $this->addConstructorDependencyWithCustomAssign($class, $propertyMetadata->getName(), $propertyMetadata->getType(), $assign);
        }
    }
    /**
     * @api doctrine
     */
    public function addConstructorDependencyWithCustomAssign(Class_ $class, string $name, ?Type $type, Assign $assign) : void
    {
        /** @var ClassMethod|null $constructorMethod */
        $constructorMethod = $class->getMethod(MethodName::CONSTRUCT);
        if ($constructorMethod instanceof ClassMethod) {
            $this->classMethodAssignManipulator->addParameterAndAssignToMethod($constructorMethod, $name, $type, $assign);
            return;
        }
        $constructorMethod = $this->nodeFactory->createPublicMethod(MethodName::CONSTRUCT);
        $this->classMethodAssignManipulator->addParameterAndAssignToMethod($constructorMethod, $name, $type, $assign);
        $this->classInsertManipulator->addAsFirstMethod($class, $constructorMethod);
    }
    /**
     * @api doctrine
     * @param Stmt[] $stmts
     */
    public function addStmtsToConstructorIfNotThereYet(Class_ $class, array $stmts) : void
    {
        $classMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (!$classMethod instanceof ClassMethod) {
            $classMethod = $this->nodeFactory->createPublicMethod(MethodName::CONSTRUCT);
            // keep parent constructor call
            if ($this->hasClassParentClassMethod($class, MethodName::CONSTRUCT)) {
                $classMethod->stmts[] = $this->createParentClassMethodCall(MethodName::CONSTRUCT);
            }
            $classMethod->stmts = \array_merge((array) $classMethod->stmts, $stmts);
            $class->stmts = \array_merge($class->stmts, [$classMethod]);
            return;
        }
        $stmts = $this->stmtsManipulator->filterOutExistingStmts($classMethod, $stmts);
        // all stmts are already there → skip
        if ($stmts === []) {
            return;
        }
        $classMethod->stmts = \array_merge($stmts, (array) $classMethod->stmts);
    }
    private function addPromotedProperty(Class_ $class, PropertyMetadata $propertyMetadata) : void
    {
        $constructClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        $param = $this->nodeFactory->createPromotedPropertyParam($propertyMetadata);
        if ($constructClassMethod instanceof ClassMethod) {
            // parameter is already added
            if ($this->hasMethodParameter($constructClassMethod, $propertyMetadata->getName())) {
                return;
            }
            $constructClassMethod->params[] = $param;
        } else {
            $constructClassMethod = $this->nodeFactory->createPublicMethod(MethodName::CONSTRUCT);
            $constructClassMethod->params[] = $param;
            $this->classInsertManipulator->addAsFirstMethod($class, $constructClassMethod);
        }
    }
    private function hasClassParentClassMethod(Class_ $class, string $methodName) : bool
    {
        $classReflection = $this->reflectionResolver->resolveClassReflection($class);
        if (!$classReflection instanceof ClassReflection) {
            return \false;
        }
        foreach ($classReflection->getParents() as $parentClassReflection) {
            if ($parentClassReflection->hasMethod($methodName)) {
                return \true;
            }
        }
        return \false;
    }
    private function createParentClassMethodCall(string $methodName) : Expression
    {
        $staticCall = new StaticCall(new Name(ObjectReference::PARENT), $methodName);
        return new Expression($staticCall);
    }
    private function isParamInConstructor(Class_ $class, string $propertyName) : bool
    {
        $constructClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (!$constructClassMethod instanceof ClassMethod) {
            return \false;
        }
        foreach ($constructClassMethod->params as $param) {
            if ($this->nodeNameResolver->isName($param, $propertyName)) {
                return \true;
            }
        }
        return \false;
    }
    private function hasClassPropertyAndDependency(Class_ $class, PropertyMetadata $propertyMetadata) : bool
    {
        $property = $this->propertyPresenceChecker->getClassContextProperty($class, $propertyMetadata);
        if ($property === null) {
            return \false;
        }
        if (!$this->autowiredClassMethodOrPropertyAnalyzer->detect($property)) {
            return $this->isParamInConstructor($class, $propertyMetadata->getName());
        }
        // is inject/autowired property?
        return $property instanceof Property;
    }
    private function hasMethodParameter(ClassMethod $classMethod, string $name) : bool
    {
        foreach ($classMethod->params as $param) {
            if ($this->nodeNameResolver->isName($param->var, $name)) {
                return \true;
            }
        }
        return \false;
    }
    private function shouldAddPromotedProperty(Class_ $class, PropertyMetadata $propertyMetadata) : bool
    {
        if (!$this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::PROPERTY_PROMOTION)) {
            return \false;
        }
        // only if the property does not exist yet
        $existingProperty = $class->getProperty($propertyMetadata->getName());
        return !$existingProperty instanceof Property;
    }
}
