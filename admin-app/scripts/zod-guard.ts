import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

export interface ZodGuardViolation {
	file: string;
	line: number;
	rule:
		| 'raw-network-call'
		| 'generic-network-trust-cast'
		| 'boundary-type-assertion'
		| 'unparsed-storage-read'
		| 'unparsed-json'
		| 'unparsed-runtime-config';
	excerpt: string;
}

const rawTransportFiles = new Set(['src/lib/api/client.ts', 'src/lib/api/http.ts']);
const storageTransportFiles = new Set([
	'src/lib/api/client.ts',
	'src/lib/storage/validated-storage.ts'
]);
const rawNetworkModules = new Set(['$lib/wp', '$lib/api/http']);
const rawNetworkExports = new Set(['wpFetch', 'apiFetch']);
const parsedBoundaryMethods = new Set(['requestParsed', 'requestEndpoint', 'apiFetchParsed']);
const runtimeConfigBoundaryFile = 'src/lib/schemas/runtime-config.ts';

export function findZodGuardViolations(file: string, source: string): ZodGuardViolation[] {
	const normalizedFile = file.replaceAll('\\', '/');
	const rawIdentifiers = new Set<string>(['fetch']);
	const rawNamespaces = new Set<string>();
	const jsonStringifiedIdentifiers = new Set<string>();
	const jsonParseIdentifiers = new Set<string>();
	const violations: ZodGuardViolation[] = [];

	for (const unit of parseUnits(normalizedFile, source)) {
		const runtimeGlobalAliases = new Set<string>(['window', 'globalThis']);
		const rawGlobalAliases = new Set<string>(['globalThis', 'window', 'self']);
		const storageObjectAliases = new Set<string>(['localStorage', 'sessionStorage']);
		const storageIdentifiers = new Set<string>();
		for (const statement of unit.sourceFile.statements) {
			if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier)) {
				continue;
			}

			if (!rawNetworkModules.has(statement.moduleSpecifier.text)) {
				continue;
			}

			if (
				statement.importClause?.namedBindings &&
				ts.isNamespaceImport(statement.importClause.namedBindings)
			) {
				rawNamespaces.add(statement.importClause.namedBindings.name.text);
			}

			for (const element of statement.importClause?.namedBindings &&
			ts.isNamedImports(statement.importClause.namedBindings)
				? statement.importClause.namedBindings.elements
				: []) {
				const importedName = element.propertyName?.text ?? element.name.text;
				if (rawNetworkExports.has(importedName)) {
					rawIdentifiers.add(element.name.text);
				}
			}
		}

		let aliasesChanged = true;
		while (aliasesChanged) {
			aliasesChanged = false;
			walk(unit.sourceFile, (node) => {
				if (!ts.isVariableDeclaration(node) || !node.initializer) {
					return;
				}
				if (
					ts.isIdentifier(node.name) &&
					ts.isIdentifier(node.initializer) &&
					runtimeGlobalAliases.has(node.initializer.text) &&
					!runtimeGlobalAliases.has(node.name.text)
				) {
					runtimeGlobalAliases.add(node.name.text);
					aliasesChanged = true;
				}
				if (
					ts.isIdentifier(node.name) &&
					ts.isIdentifier(node.initializer) &&
					rawGlobalAliases.has(node.initializer.text) &&
					!rawGlobalAliases.has(node.name.text)
				) {
					rawGlobalAliases.add(node.name.text);
					aliasesChanged = true;
				}
				if (
					ts.isIdentifier(node.name) &&
					ts.isIdentifier(node.initializer) &&
					storageObjectAliases.has(node.initializer.text) &&
					!storageObjectAliases.has(node.name.text)
				) {
					storageObjectAliases.add(node.name.text);
					aliasesChanged = true;
				}
				if (ts.isObjectBindingPattern(node.name) && ts.isIdentifier(node.initializer)) {
					for (const element of node.name.elements) {
						if (!ts.isIdentifier(element.name)) continue;
						const importedName = element.propertyName ?? element.name;
						const memberName = staticPropertyName(importedName);
						if (
							rawNamespaces.has(node.initializer.text) &&
							rawNetworkExports.has(staticPropertyName(importedName) ?? '') &&
							!rawIdentifiers.has(element.name.text)
						) {
							rawIdentifiers.add(element.name.text);
							aliasesChanged = true;
						}
						if (
							rawGlobalAliases.has(node.initializer.text) &&
							memberName === 'fetch' &&
							!rawIdentifiers.has(element.name.text)
						) {
							rawIdentifiers.add(element.name.text);
							aliasesChanged = true;
						}
						if (
							storageObjectAliases.has(node.initializer.text) &&
							memberName === 'getItem' &&
							!storageIdentifiers.has(element.name.text)
						) {
							storageIdentifiers.add(element.name.text);
							aliasesChanged = true;
						}
						if (
							node.initializer.text === 'JSON' &&
							memberName === 'parse' &&
							!jsonParseIdentifiers.has(element.name.text)
						) {
							jsonParseIdentifiers.add(element.name.text);
							aliasesChanged = true;
						}
					}
					return;
				}
				if (!ts.isIdentifier(node.name)) return;
				if (isJsonStringify(node.initializer)) {
					jsonStringifiedIdentifiers.add(node.name.text);
				}
				if (isJsonParseAccessor(node.initializer) && !jsonParseIdentifiers.has(node.name.text)) {
					jsonParseIdentifiers.add(node.name.text);
					aliasesChanged = true;
				}
				if (
					containsRawNetworkReference(
						node.initializer,
						rawIdentifiers,
						rawNamespaces,
						rawGlobalAliases
					) &&
					!rawIdentifiers.has(node.name.text)
				) {
					rawIdentifiers.add(node.name.text);
					aliasesChanged = true;
				}
				if (
					isStorageAccessor(node.initializer, storageObjectAliases) &&
					!storageIdentifiers.has(node.name.text)
				) {
					storageIdentifiers.add(node.name.text);
					aliasesChanged = true;
				}
			});
		}

		walk(unit.sourceFile, (node) => {
			if (
				normalizedFile !== runtimeConfigBoundaryFile &&
				(isRuntimeConfigAccess(node, runtimeGlobalAliases) ||
					isRuntimeConfigDestructure(node, runtimeGlobalAliases))
			) {
				pushViolation(violations, normalizedFile, source, unit, node, 'unparsed-runtime-config');
			}
			if (
				ts.isFunctionDeclaration(node) &&
				node.name &&
				rawNetworkExports.has(node.name.text) &&
				(node.typeParameters?.length ?? 0) > 0
			) {
				pushViolation(violations, normalizedFile, source, unit, node, 'generic-network-trust-cast');
			}
			if (
				ts.isMethodDeclaration(node) &&
				ts.isIdentifier(node.name) &&
				node.name.text === 'request' &&
				(node.typeParameters?.length ?? 0) > 0
			) {
				pushViolation(violations, normalizedFile, source, unit, node, 'generic-network-trust-cast');
			}
			if (ts.isCallExpression(node)) {
				const rawCall = isRawNetworkCall(node, rawIdentifiers, rawNamespaces, rawGlobalAliases);
				if (rawCall && !rawTransportFiles.has(normalizedFile)) {
					pushViolation(violations, normalizedFile, source, unit, node, 'raw-network-call');
				}

				if (
					rawCall &&
					!rawTransportFiles.has(normalizedFile) &&
					node.typeArguments &&
					node.typeArguments.length > 0
				) {
					pushViolation(
						violations,
						normalizedFile,
						source,
						unit,
						node,
						'generic-network-trust-cast'
					);
				}

				if (
					isStorageRead(node, storageIdentifiers, storageObjectAliases) &&
					!storageTransportFiles.has(normalizedFile)
				) {
					pushViolation(violations, normalizedFile, source, unit, node, 'unparsed-storage-read');
				}

				if (
					isJsonParse(node, jsonParseIdentifiers) &&
					!rawTransportFiles.has(normalizedFile) &&
					!isSchemaParseArgument(node) &&
					!isParseOfJsonStringifyResult(node, jsonStringifiedIdentifiers)
				) {
					pushViolation(violations, normalizedFile, source, unit, node, 'unparsed-json');
				}
			}

			if (
				(ts.isAsExpression(node) || ts.isTypeAssertionExpression(node)) &&
				!rawTransportFiles.has(normalizedFile) &&
				containsBoundaryExpression(
					node.expression,
					rawIdentifiers,
					rawNamespaces,
					rawGlobalAliases,
					jsonParseIdentifiers,
					jsonStringifiedIdentifiers
				)
			) {
				pushViolation(violations, normalizedFile, source, unit, node, 'boundary-type-assertion');
			}
		});
	}

	return deduplicate(violations);
}

export function scanZodTrustBoundaries(rootDirectory: string): ZodGuardViolation[] {
	const sourceRoot = resolve(rootDirectory, 'src');
	return sourceFiles(sourceRoot).flatMap((absoluteFile) => {
		const relativeFile = relative(rootDirectory, absoluteFile).replaceAll('\\', '/');
		return findZodGuardViolations(relativeFile, readFileSync(absoluteFile, 'utf8'));
	});
}

interface ParseUnit {
	sourceFile: ts.SourceFile;
	baseOffset: number;
}

function parseUnits(file: string, source: string): ParseUnit[] {
	if (!file.endsWith('.svelte')) {
		return [{ sourceFile: parseTypeScript(file, source), baseOffset: 0 }];
	}

	const units: ParseUnit[] = [];
	for (const match of source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/giu)) {
		const script = match[1] ?? '';
		const fullMatch = match[0];
		const scriptOffset = (match.index ?? 0) + fullMatch.indexOf(script);
		units.push({ sourceFile: parseTypeScript(`${file}.ts`, script), baseOffset: scriptOffset });
	}
	return units;
}

function parseTypeScript(file: string, source: string): ts.SourceFile {
	return ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
}

function walk(node: ts.Node, visitor: (node: ts.Node) => void): void {
	visitor(node);
	node.forEachChild((child) => walk(child, visitor));
}

function containsRawNetworkReference(
	node: ts.Node,
	rawIdentifiers: Set<string>,
	rawNamespaces: Set<string>,
	rawGlobalAliases: Set<string>
): boolean {
	let found = false;
	walk(node, (child) => {
		if (ts.isIdentifier(child) && rawIdentifiers.has(child.text)) {
			found = true;
		}
		if (isRawNetworkExpression(child, rawNamespaces, rawGlobalAliases)) {
			found = true;
		}
	});
	return found;
}

function isRawNetworkCall(
	node: ts.CallExpression,
	rawIdentifiers: Set<string>,
	rawNamespaces: Set<string>,
	rawGlobalAliases: Set<string>
): boolean {
	const callee = unwrapExpression(node.expression);
	if (ts.isIdentifier(callee)) {
		return rawIdentifiers.has(callee.text);
	}
	if (isRawNetworkExpression(callee, rawNamespaces, rawGlobalAliases)) return true;
	if (!ts.isPropertyAccessExpression(callee)) return false;
	if (callee.name.text === 'request') return true;
	if (
		callee.name.text === 'fetch' &&
		ts.isIdentifier(callee.expression) &&
		rawGlobalAliases.has(callee.expression.text)
	) {
		return true;
	}
	return (
		rawNetworkExports.has(callee.name.text) &&
		ts.isIdentifier(callee.expression) &&
		rawNamespaces.has(callee.expression.text)
	);
}

function isRawNetworkExpression(
	node: ts.Node,
	rawNamespaces: Set<string>,
	rawGlobalAliases: Set<string>
): boolean {
	const expression = ts.isExpression(node) ? unwrapExpression(node) : null;
	if (!expression) return false;

	if (ts.isPropertyAccessExpression(expression)) {
		if (expression.name.text === 'request') return true;
		if (
			expression.name.text === 'fetch' &&
			ts.isIdentifier(expression.expression) &&
			rawGlobalAliases.has(expression.expression.text)
		) {
			return true;
		}
		return (
			rawNetworkExports.has(expression.name.text) &&
			ts.isIdentifier(expression.expression) &&
			rawNamespaces.has(expression.expression.text)
		);
	}

	if (ts.isElementAccessExpression(expression) && ts.isIdentifier(expression.expression)) {
		const propertyName = staticPropertyName(expression.argumentExpression);
		if (rawGlobalAliases.has(expression.expression.text) && propertyName === 'fetch') {
			return true;
		}
		if (
			rawNamespaces.has(expression.expression.text) &&
			propertyName !== null &&
			rawNetworkExports.has(propertyName)
		) {
			return true;
		}
	}

	if (isReflectGet(expression, rawGlobalAliases, 'fetch')) return true;
	return [...rawNetworkExports].some((exportName) =>
		isReflectGet(expression, rawNamespaces, exportName)
	);
}

function isRuntimeConfigAccess(node: ts.Node, runtimeGlobalAliases: Set<string>): boolean {
	if (
		ts.isPropertyAccessExpression(node) &&
		node.name.text === 'sentientFormsConfig' &&
		ts.isIdentifier(node.expression) &&
		runtimeGlobalAliases.has(node.expression.text)
	) {
		return true;
	}

	if (
		ts.isElementAccessExpression(node) &&
		ts.isIdentifier(node.expression) &&
		runtimeGlobalAliases.has(node.expression.text) &&
		staticPropertyName(node.argumentExpression) === 'sentientFormsConfig'
	) {
		return true;
	}

	return isReflectGet(node, runtimeGlobalAliases, 'sentientFormsConfig');
}

function isRuntimeConfigDestructure(node: ts.Node, runtimeGlobalAliases: Set<string>): boolean {
	if (
		!ts.isVariableDeclaration(node) ||
		!ts.isObjectBindingPattern(node.name) ||
		!node.initializer ||
		!ts.isIdentifier(node.initializer) ||
		!runtimeGlobalAliases.has(node.initializer.text)
	) {
		return false;
	}

	return node.name.elements.some((element) => {
		const propertyName = element.propertyName ?? element.name;
		return staticPropertyName(propertyName) === 'sentientFormsConfig';
	});
}

function isReflectGet(node: ts.Node, objectNames: Set<string>, propertyName: string): boolean {
	if (!ts.isCallExpression(node)) return false;
	const callee = unwrapExpression(node.expression);
	if (
		!ts.isPropertyAccessExpression(callee) ||
		!ts.isIdentifier(callee.expression) ||
		callee.expression.text !== 'Reflect' ||
		callee.name.text !== 'get'
	) {
		return false;
	}

	const [target, property] = node.arguments;
	return Boolean(
		target &&
		ts.isIdentifier(target) &&
		objectNames.has(target.text) &&
		staticPropertyName(property) === propertyName
	);
}

function staticPropertyName(node: ts.Node | undefined): string | null {
	if (!node) return null;
	if (
		ts.isIdentifier(node) ||
		ts.isStringLiteral(node) ||
		ts.isNoSubstitutionTemplateLiteral(node)
	) {
		return node.text;
	}
	return null;
}

function isStorageRead(
	node: ts.CallExpression,
	storageIdentifiers: Set<string>,
	storageObjectAliases: Set<string>
): boolean {
	const callee = unwrapExpression(node.expression);
	return (
		(ts.isIdentifier(callee) && storageIdentifiers.has(callee.text)) ||
		isStorageAccessor(callee, storageObjectAliases)
	);
}

function isStorageAccessor(node: ts.Node, storageObjectAliases: Set<string>): boolean {
	const expression = ts.isExpression(node) ? unwrapExpression(node) : null;
	if (!expression) return false;
	if (
		ts.isPropertyAccessExpression(expression) &&
		ts.isIdentifier(expression.expression) &&
		storageObjectAliases.has(expression.expression.text) &&
		expression.name.text === 'getItem'
	) {
		return true;
	}
	if (
		ts.isElementAccessExpression(expression) &&
		ts.isIdentifier(expression.expression) &&
		storageObjectAliases.has(expression.expression.text) &&
		staticPropertyName(expression.argumentExpression) === 'getItem'
	) {
		return true;
	}
	return isReflectGet(expression, storageObjectAliases, 'getItem');
}

function isJsonParse(node: ts.CallExpression, jsonParseIdentifiers: Set<string>): boolean {
	const callee = unwrapExpression(node.expression);
	if (ts.isIdentifier(callee) && jsonParseIdentifiers.has(callee.text)) return true;
	return isJsonParseAccessor(callee);
}

function isJsonParseAccessor(node: ts.Node): boolean {
	const callee = ts.isExpression(node) ? unwrapExpression(node) : null;
	if (!callee) return false;
	if (
		ts.isPropertyAccessExpression(callee) &&
		ts.isIdentifier(callee.expression) &&
		callee.expression.text === 'JSON' &&
		callee.name.text === 'parse'
	) {
		return true;
	}
	if (
		ts.isElementAccessExpression(callee) &&
		ts.isIdentifier(callee.expression) &&
		callee.expression.text === 'JSON' &&
		staticPropertyName(callee.argumentExpression) === 'parse'
	) {
		return true;
	}
	return isReflectGet(callee, new Set(['JSON']), 'parse');
}

function isJsonStringify(node: ts.Expression): boolean {
	const expression = unwrapExpression(node);
	if (!ts.isCallExpression(expression)) return false;
	const callee = unwrapExpression(expression.expression);
	return (
		ts.isPropertyAccessExpression(callee) &&
		ts.isIdentifier(callee.expression) &&
		callee.expression.text === 'JSON' &&
		callee.name.text === 'stringify'
	);
}

function isParseOfJsonStringifyResult(
	node: ts.CallExpression,
	jsonStringifiedIdentifiers: Set<string>
): boolean {
	const argument = node.arguments[0];
	return Boolean(
		argument && ts.isIdentifier(argument) && jsonStringifiedIdentifiers.has(argument.text)
	);
}

function isSchemaParseArgument(node: ts.CallExpression): boolean {
	const parent = node.parent;
	if (!ts.isCallExpression(parent) || !parent.arguments.includes(node)) {
		return false;
	}
	const callee = unwrapExpression(parent.expression);
	return (
		ts.isPropertyAccessExpression(callee) &&
		(callee.name.text === 'parse' || callee.name.text === 'safeParse')
	);
}

function containsBoundaryExpression(
	node: ts.Node,
	rawIdentifiers: Set<string>,
	rawNamespaces: Set<string>,
	rawGlobalAliases: Set<string>,
	jsonParseIdentifiers: Set<string>,
	jsonStringifiedIdentifiers: Set<string>
): boolean {
	let found = false;
	walk(node, (child) => {
		if (ts.isIdentifier(child) && rawIdentifiers.has(child.text)) {
			found = true;
		}
		if (ts.isCallExpression(child)) {
			const callee = unwrapExpression(child.expression);
			if (
				isRawNetworkCall(child, rawIdentifiers, rawNamespaces, rawGlobalAliases) ||
				(isJsonParse(child, jsonParseIdentifiers) &&
					!isParseOfJsonStringifyResult(child, jsonStringifiedIdentifiers))
			) {
				found = true;
			}
			if (ts.isPropertyAccessExpression(callee) && parsedBoundaryMethods.has(callee.name.text)) {
				found = true;
			}
		}
	});
	return found;
}

function unwrapExpression(expression: ts.Expression): ts.Expression {
	let current = expression;
	while (
		ts.isParenthesizedExpression(current) ||
		ts.isAsExpression(current) ||
		ts.isTypeAssertionExpression(current)
	) {
		current = current.expression;
	}
	return current;
}

function pushViolation(
	violations: ZodGuardViolation[],
	file: string,
	source: string,
	unit: ParseUnit,
	node: ts.Node,
	rule: ZodGuardViolation['rule']
): void {
	const absoluteOffset = unit.baseOffset + node.getStart(unit.sourceFile);
	const line = source.slice(0, absoluteOffset).split(/\r?\n/u).length;
	violations.push({
		file,
		line,
		rule,
		excerpt: source.split(/\r?\n/u)[line - 1]?.trim() ?? ''
	});
}

function deduplicate(violations: ZodGuardViolation[]): ZodGuardViolation[] {
	const seen = new Set<string>();
	return violations.filter((item) => {
		const key = `${item.file}\u0000${item.line}\u0000${item.rule}`;
		if (seen.has(key)) {
			return false;
		}
		seen.add(key);
		return true;
	});
}

function sourceFiles(directory: string): string[] {
	return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const absolutePath = join(directory, entry.name);
		if (entry.isDirectory()) {
			return sourceFiles(absolutePath);
		}
		return /\.(?:js|ts|svelte)$/u.test(entry.name) ? [absolutePath] : [];
	});
}

function run(): void {
	const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
	const violations = scanZodTrustBoundaries(projectRoot);
	if (violations.length === 0) {
		console.log('Zod guard passed: every network and storage boundary is parsed.');
		return;
	}

	for (const item of violations) {
		console.error(`${item.file}:${item.line} [${item.rule}] ${item.excerpt}`);
	}
	process.exitCode = 1;
}

const invokedFile = process.argv[1] ? resolve(process.argv[1]) : '';
if (invokedFile === fileURLToPath(import.meta.url)) {
	run();
}
