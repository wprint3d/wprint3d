module.exports = function(api) {
  api.cache(true);

  return {
    presets: [ 'babel-preset-expo' ],
    plugins: [
      'module:react-native-dotenv',
      'react-native-paper/babel',
      '@babel/plugin-proposal-export-namespace-from',
      'react-native-reanimated/plugin'
    ]
  };
};
