// @ts-check
/** Remove the throwaway tree built in playwright.standalone.config.js. */
const { cleanup } = require('./standalone-setup.js');

module.exports = () => {
  if (process.env.STANDALONE_ROOT) {
    cleanup(process.env.STANDALONE_ROOT);
  }
};
