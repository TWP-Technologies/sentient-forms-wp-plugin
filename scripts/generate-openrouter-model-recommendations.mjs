#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const pluginRoot = path.resolve(__dirname, '..');

const outputPath = path.join(pluginRoot, 'includes', 'data', 'openrouter-model-recommendations.php');
const apiBase = 'https://openrouter.ai/api/v1/models';

const categories = {
	programming: 'programming',
	roleplay: 'roleplay',
	marketing: 'marketing',
	seo: 'marketing/seo',
	technology: 'technology',
	science: 'science',
	translation: 'translation',
	legal: 'legal',
	finance: 'finance',
	health: 'health',
	trivia: 'trivia',
	academia: 'academia'
};

const curatedIds = [
	'openrouter/free',
	'openrouter/auto',
	'openai/gpt-5.5-pro',
	'openai/gpt-5.5',
	'openai/gpt-5.4-pro',
	'openai/gpt-5.4',
	'openai/gpt-5.4-mini',
	'openai/gpt-5.4-nano',
	'openai/gpt-5-mini',
	'openai/gpt-5-nano',
	'openai/gpt-5.3-codex',
	'openai/gpt-oss-120b',
	'openai/gpt-oss-20b',
	'anthropic/claude-opus-4.7',
	'anthropic/claude-opus-4.6',
	'anthropic/claude-sonnet-4.6',
	'anthropic/claude-haiku-4.5',
	'anthropic/claude-sonnet-4.5',
	'google/gemini-3.1-pro-preview',
	'google/gemini-3-flash-preview',
	'google/gemini-3.1-flash-lite-preview',
	'google/gemini-2.5-pro',
	'google/gemini-2.5-flash',
	'google/gemini-2.5-flash-lite',
	'deepseek/deepseek-v4-pro',
	'deepseek/deepseek-v4-flash',
	'deepseek/deepseek-v3.2',
	'z-ai/glm-5.1',
	'z-ai/glm-5',
	'z-ai/glm-4.5-air',
	'moonshotai/kimi-k2.6',
	'moonshotai/kimi-k2.5',
	'moonshotai/kimi-k2-thinking',
	'qwen/qwen3.6-max-preview',
	'qwen/qwen3.6-plus',
	'qwen/qwen3.5-flash-02-23',
	'qwen/qwen3.5-9b',
	'x-ai/grok-4.1-fast',
	'x-ai/grok-4-fast',
	'minimax/minimax-m2.7',
	'minimax/minimax-m2.5',
	'stepfun/step-3.5-flash',
	'tencent/hy3-preview:free',
	'nvidia/nemotron-3-super-120b-a12b:free',
	'inclusionai/ling-2.6-1t:free',
	'poolside/laguna-m.1:free',
	'google/gemma-4-31b-it',
	'google/gemma-4-26b-a4b-it',
	'meta-llama/llama-3.1-70b-instruct',
	'meta-llama/llama-3.1-8b-instruct',
	'xiaomi/mimo-v2-flash'
];

const manualModels = {
	'openrouter/free': {
		id: 'openrouter/free',
		name: 'OpenRouter Free Models Router',
		free: true,
		context_length: 200000,
		input_modalities: ['text', 'image'],
		output_modalities: ['text'],
		supported_parameters: [
			'include_reasoning',
			'max_tokens',
			'reasoning',
			'response_format',
			'structured_outputs',
			'tool_choice',
			'tools'
		],
		pricing: { prompt: '0', completion: '0' },
		recommended_for: ['Free testing', 'Low-risk workflow proof'],
		recommendation_categories: ['Free model']
	},
	'openrouter/auto': {
		id: 'openrouter/auto',
		name: 'OpenRouter Auto Router',
		free: false,
		context_length: 2000000,
		input_modalities: ['text', 'image', 'audio', 'file', 'video'],
		output_modalities: ['text', 'image'],
		supported_parameters: [
			'frequency_penalty',
			'include_reasoning',
			'logit_bias',
			'logprobs',
			'max_completion_tokens',
			'max_tokens',
			'min_p',
			'presence_penalty',
			'reasoning',
			'repetition_penalty',
			'response_format',
			'seed',
			'stop',
			'structured_outputs',
			'temperature',
			'tool_choice',
			'tools',
			'top_k',
			'top_logprobs',
			'top_p',
			'web_search_options'
		],
		pricing: { prompt: '-1', completion: '-1' },
		recommended_for: ['Provider-routed fallback when a specific model is not selected'],
		recommendation_categories: ['Fallback']
	}
};

function asArray(value) {
	return Array.isArray(value) ? value : [];
}

async function fetchJson(url) {
	const response = await fetch(url, {
		headers: {
			Accept: 'application/json',
			'User-Agent': 'SentientFormsModelCatalogGenerator/1.0'
		}
	});

	if (!response.ok) {
		throw new Error(`${response.status} ${response.statusText} from ${url}`);
	}

	return response.json();
}

function modelIsTextGeneration(model) {
	const architecture = model?.architecture && typeof model.architecture === 'object' ? model.architecture : {};
	const outputModalities = asArray(model.output_modalities ?? architecture.output_modalities);
	return outputModalities.length === 0 || outputModalities.includes('text');
}

function costSymbol(model) {
	const pricing = model?.pricing && typeof model.pricing === 'object' ? model.pricing : {};
	const prompt = Number.parseFloat(String(pricing.prompt ?? '0'));
	const completion = Number.parseFloat(String(pricing.completion ?? '0'));
	const max = Math.max(prompt, completion);

	if (model.free || String(model.id ?? '').endsWith(':free')) return 'Free';
	if (!Number.isFinite(prompt) || !Number.isFinite(completion)) return 'Varies';
	if (prompt < 0 || completion < 0) return 'Varies';
	if (max === 0) return 'Free';
	if (max <= 0.000001) return '$';
	if (max <= 0.00001) return '$$';
	if (max <= 0.00005) return '$$$';
	return '$$$$';
}

function recommendationLabels(model, ranks, topTenFrequency) {
	const labels = new Set(asArray(model.recommended_for).filter((item) => typeof item === 'string'));
	const id = String(model.id ?? '');
	const name = String(model.name ?? '');
	const haystack = `${id} ${name}`.toLowerCase();

	for (const [category, rank] of Object.entries(ranks)) {
		if (rank <= 3) labels.add(`Top ${rank} ${titleCase(category)}`);
	}

	if ((topTenFrequency.get(id) ?? 0) >= 3) labels.add('Frequent OpenRouter category top-10');
	if (haystack.includes('code') || ranks.programming) labels.add('Code generation');
	if (haystack.includes('flash') || haystack.includes('mini') || haystack.includes('fast')) {
		labels.add('Speed');
	}
	if (asArray(model.supported_parameters).includes('response_format')) {
		labels.add('Structured output');
	}
	if (asArray(model.supported_parameters).some((parameter) => ['tools', 'tool_choice'].includes(parameter))) {
		labels.add('Tool calling');
	}
	if ((model.context_length ?? 0) >= 128000) labels.add('Long context');

	return [...labels];
}

function categoryLabels(ranks) {
	const labels = new Set();
	for (const [category, rank] of Object.entries(ranks)) {
		if (rank <= 10) labels.add(titleCase(category));
	}
	return [...labels];
}

function titleCase(input) {
	return String(input)
		.replace(/_/g, ' ')
		.replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function cleanModel(model, ranks, topTenFrequency, retrievedAt) {
	const architecture = model?.architecture && typeof model.architecture === 'object' ? model.architecture : {};
	const inputModalities = asArray(model.input_modalities ?? architecture.input_modalities);
	const outputModalities = asArray(model.output_modalities ?? architecture.output_modalities);
	const pricing = model?.pricing && typeof model.pricing === 'object' ? model.pricing : {};
	const sourceUrls = [
		`https://openrouter.ai/${String(model.id).replace(/^~?/, '')}`,
		'https://openrouter.ai/models'
	];

	return {
		...model,
		description: cleanDescription(model.description ?? ''),
		free: Boolean(model.free || String(model.id ?? '').endsWith(':free') || costSymbol(model) === 'Free'),
		input_modalities: inputModalities,
		output_modalities: outputModalities,
		pricing,
		cost_symbol: costSymbol(model),
		recommended_for: recommendationLabels(model, ranks, topTenFrequency),
		recommendation_categories: categoryLabels(ranks),
		category_rankings: ranks,
		ranking_snapshot: {
			source: 'openrouter.ai category filters',
			retrieved_at: retrievedAt,
			categories
		},
		source_urls: sourceUrls
	};
}

function cleanDescription(value) {
	return asciiSafe(value)
		.replace(/\[([^\]]+)\]\((?:https?:\/\/|\/)[^)]+\)/gi, '$1')
		.replace(/https?:\/\/\S+/gi, '')
		.replace(/\s+/g, ' ')
		.trim();
}

function phpString(value) {
	return `'${asciiSafe(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function asciiSafe(value) {
	return String(value)
		.normalize('NFKD')
		.replace(/[\u2018\u2019]/g, "'")
		.replace(/[\u201C\u201D]/g, '"')
		.replace(/[\u2013\u2014]/g, '-')
		.replace(/[^\x09\x0A\x0D\x20-\x7E]/g, '');
}

function phpExport(value, depth = 0) {
	const indent = '\t'.repeat(depth);
	const childIndent = '\t'.repeat(depth + 1);

	if (value === null || value === undefined) return 'null';
	if (typeof value === 'boolean') return value ? 'true' : 'false';
	if (typeof value === 'number') return Number.isFinite(value) ? String(value) : 'null';
	if (typeof value === 'string') return phpString(value);
	if (Array.isArray(value)) {
		if (value.length === 0) return '[]';
		const lines = value.map((item) => `${childIndent}${phpExport(item, depth + 1)},`);
		return `[\n${lines.join('\n')}\n${indent}]`;
	}
	if (typeof value === 'object') {
		const entries = Object.entries(value).filter(([, entryValue]) => entryValue !== undefined);
		if (entries.length === 0) return '[]';
		const lines = entries.map(
			([key, entryValue]) => `${childIndent}${phpString(key)} => ${phpExport(entryValue, depth + 1)},`
		);
		return `[\n${lines.join('\n')}\n${indent}]`;
	}
	return 'null';
}

async function main() {
	const retrievedAt = new Date().toISOString();
	const allModelsResponse = await fetchJson(apiBase);
	const allModels = new Map();
	for (const model of asArray(allModelsResponse.data)) {
		if (model?.id && modelIsTextGeneration(model)) {
			allModels.set(String(model.id), model);
		}
	}

	const ranksByModel = new Map();
	const topTenFrequency = new Map();
	const selectedIds = new Set(curatedIds);

	for (const [category, slug] of Object.entries(categories)) {
		const response = await fetchJson(`${apiBase}?category=${encodeURIComponent(slug)}`);
		asArray(response.data)
			.filter(modelIsTextGeneration)
			.slice(0, 10)
			.forEach((model, index) => {
				const id = String(model.id ?? '');
				if (!id) return;
				const rank = index + 1;
				const ranks = ranksByModel.get(id) ?? {};
				ranks[category] = rank;
				ranksByModel.set(id, ranks);
				topTenFrequency.set(id, (topTenFrequency.get(id) ?? 0) + 1);
				if (rank <= 3) selectedIds.add(id);
			});
	}

	for (const [id, frequency] of topTenFrequency) {
		if (frequency >= 2) selectedIds.add(id);
	}

	const models = [];
	for (const id of selectedIds) {
		const source = manualModels[id] ?? allModels.get(id);
		if (!source) continue;
		models.push(cleanModel(source, ranksByModel.get(id) ?? {}, topTenFrequency, retrievedAt));
	}

	for (const [id, source] of Object.entries(manualModels)) {
		if (!models.some((model) => model.id === id)) {
			models.push(cleanModel(source, ranksByModel.get(id) ?? {}, topTenFrequency, retrievedAt));
		}
	}

	models.sort((a, b) => String(a.name ?? a.id).localeCompare(String(b.name ?? b.id)));

	await fs.mkdir(path.dirname(outputPath), { recursive: true });
	await fs.writeFile(
		outputPath,
		`<?php\n/**\n * Generated OpenRouter model recommendation snapshot.\n *\n * Source: https://openrouter.ai/api/v1/models and category filters.\n * Generated: ${retrievedAt}\n *\n * @package SentientForms\n */\n\nreturn ${phpExport(models)};\n`,
		'utf8'
	);

	console.log(`Wrote ${models.length} OpenRouter model recommendations to ${outputPath}`);
}

main().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
