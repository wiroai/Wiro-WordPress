import js from '@eslint/js';
import globals from 'globals';

export default [
	{
		ignores: [ 'build/**', 'node_modules/**', 'vendor/**' ],
	},
	js.configs.recommended,
	{
		files: [ 'assets/js/**/*.js', 'blocks/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser,
				wp: 'readonly',
			},
		},
		rules: {
			eqeqeq: 'error',
			'no-var': 'error',
			'prefer-const': 'error',
		},
	},
];
