/**
 * Simple color utilities for consistent styling.
 * Provides direct chalk functions for common use cases.
 *
 * @since 1.0.0
 */

import chalk from 'chalk';

export const defaultChalk = chalk;

/**
 * Simple color functions for common message types
 */
export const colors = {
	error: chalk.red,
	warn: chalk.yellow,
	warning: chalk.yellow,
	info: chalk.blue,
	success: chalk.green,
	debug: chalk.gray,
	notice: chalk.cyan,
	highlight: chalk.magenta,
	muted: chalk.gray,
	neutral: chalk.white,
};

/**
 * Gets a color function for a message type.
 *
 * @since 1.0.0
 *
 * @param {string} type - Message type.
 *
 * @return {Function} Chalk color function.
 */
export function getColor(type) {
	return colors[type] || colors.neutral;
}

/**
 * Gets a styled (bright) color function.
 *
 * @since 1.0.0
 *
 * @param {string} type - Message type.
 *
 * @return {Function} Styled chalk color function.
 */
export function getStyledColor(type) {
	const baseColor = getColor(type);
	// For most colors, add bright variant.
	if (type === 'error') return chalk.redBright;
	if (type === 'warn' || type === 'warning') return chalk.yellowBright;
	if (type === 'info') return chalk.blueBright;
	if (type === 'success') return chalk.greenBright;
	if (type === 'notice') return chalk.cyanBright;
	if (type === 'highlight') return chalk.magentaBright;

	return baseColor;
}

/**
 * Gets a bold color function.
 *
 * @since 1.0.0
 *
 * @param {string} type - Message type.
 *
 * @return {Function} Bold chalk color function.
 */
export function getBoldColor(type) {
	return getStyledColor(type).bold;
}

/**
 * Creates a simple color map for multiple types.
 *
 * @since 1.0.0
 *
 * @param {Array<string>} types - Message types.
 *
 * @return {Object} Color map.
 */
export function createColorMap(types) {
	const colorMap = {};

	for (const type of types) {
		colorMap[type] = getColor(type);
	}

	return colorMap;
}
