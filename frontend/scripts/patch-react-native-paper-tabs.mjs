import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

const packageRoot = path.resolve("node_modules/react-native-paper-tabs");
const useLatestCallbackRoot = path.resolve("node_modules/use-latest-callback");
const reactNativePaperRoot = path.resolve("node_modules/react-native-paper");
const snackbarStackRoot = path.resolve("node_modules/react-native-paper-snackbar-stack");

const replaceOnce = (contents, from, to, errorMessage) => {
  if (to && contents.includes(to)) {
    return contents;
  }

  if (!contents.includes(from)) {
    throw new Error(errorMessage);
  }

  return contents.replace(from, to);
};

const patchDefinitions = [
  {
    file: "src/TabsHeaderItem.tsx",
    checks: [
      "import { Badge, Icon, Text, TouchableRipple } from 'react-native-paper';",
      "<Icon source={tab.props.icon} color={color as any} size={24} />",
    ],
    replacements: [
      {
        from: "import { Badge, Text, TouchableRipple } from 'react-native-paper';",
        to: "import { Badge, Icon, Text, TouchableRipple } from 'react-native-paper';",
      },
      {
        from: "import MaterialCommunityIcon from './MaterialCommunityIcon';\n",
        to: "",
      },
      {
        from: `              <MaterialCommunityIcon
                selectable={false}
                accessibilityElementsHidden={true}
                importantForAccessibility="no"
                name={tab.props.icon || ''}
                style={{ color: color, opacity }}
                size={24}
              />`,
        to: "              <Icon source={tab.props.icon} color={color as any} size={24} />",
      },
    ],
  },
  {
    file: "lib/module/TabsHeaderItem.js",
    checks: [
      "import { Badge, Icon, Text, TouchableRipple } from 'react-native-paper';",
      "source: tab.props.icon,",
    ],
    replacements: [
      {
        from: "import { Badge, Text, TouchableRipple } from 'react-native-paper';",
        to: "import { Badge, Icon, Text, TouchableRipple } from 'react-native-paper';",
      },
      {
        from: "import MaterialCommunityIcon from './MaterialCommunityIcon';\n",
        to: "",
      },
      {
        from: `  }, /*#__PURE__*/React.createElement(MaterialCommunityIcon, {
    selectable: false,
    accessibilityElementsHidden: true,
    importantForAccessibility: "no",
    name: tab.props.icon || '',
    style: {
      color: color,
      opacity
    },
    size: 24
  })) : null,`,
        to: `  }, /*#__PURE__*/React.createElement(Icon, {
    source: tab.props.icon,
    color: color,
    size: 24
  })) : null,`,
      },
    ],
  },
  {
    file: "lib/commonjs/TabsHeaderItem.js",
    checks: [
      "source: tab.props.icon,",
      " /*#__PURE__*/React.createElement(_reactNativePaper.Icon, {",
    ],
    replacements: [
      {
        from: "var _MaterialCommunityIcon = _interopRequireDefault(require(\"./MaterialCommunityIcon\"));\n",
        to: "",
      },
      {
        from: `  }, /*#__PURE__*/React.createElement(_MaterialCommunityIcon.default, {
    selectable: false,
    accessibilityElementsHidden: true,
    importantForAccessibility: "no",
    name: tab.props.icon || '',
    style: {
      color: color,
      opacity
    },
    size: 24
  })) : null,`,
        to: `  }, /*#__PURE__*/React.createElement(_reactNativePaper.Icon, {
    source: tab.props.icon,
    color: color,
    size: 24
  })) : null,`,
      },
    ],
  },
];

for (const definition of patchDefinitions) {
  const targetPath = path.join(packageRoot, definition.file);
  let contents = readFileSync(targetPath, "utf8");

  const alreadyPatched = definition.checks.every((needle) => contents.includes(needle));

  if (!alreadyPatched) {
    for (const replacement of definition.replacements) {
      if (contents.includes(replacement.to)) {
        continue;
      }

      if (!contents.includes(replacement.from)) {
        throw new Error(`Unable to apply react-native-paper-tabs patch to ${definition.file}. Missing expected snippet.`);
      }

      contents = contents.replace(replacement.from, replacement.to);
    }

    writeFileSync(targetPath, contents, "utf8");
  }
}

const reactNativePaperPatches = [
  {
    file: "lib/module/components/Modal.js",
    replacements: [
      {
        from: "const DEFAULT_DURATION = 220;\n",
        to: `const useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};
const DEFAULT_DURATION = 220;\n`,
        error: "Unable to inject stable callback helper into react-native-paper Modal module build.",
      },
      {
        from: "const onDismissCallback = useLatestCallback(onDismiss);",
        to: "const onDismissCallback = useStableLatestCallback(onDismiss);",
        error: "Unable to patch react-native-paper Modal module callback usage.",
      },
    ],
  },
  {
    file: "lib/module/components/Snackbar.js",
    replacements: [
      {
        from: "const DURATION_SHORT = 4000;\n",
        to: `const useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};
const DURATION_SHORT = 4000;\n`,
        error: "Unable to inject stable callback helper into react-native-paper Snackbar module build.",
      },
      {
        from: "const animateShow = useLatestCallback(() => {",
        to: "const animateShow = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar module animateShow callback usage.",
      },
      {
        from: "const handleOnVisible = useLatestCallback(() => {",
        to: "const handleOnVisible = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar module handleOnVisible callback usage.",
      },
      {
        from: "const handleOnHidden = useLatestCallback(() => {",
        to: "const handleOnHidden = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar module handleOnHidden callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/Modal.js",
    replacements: [
      {
        from: "const DEFAULT_DURATION = 220;\n",
        to: `function useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}
const DEFAULT_DURATION = 220;\n`,
        error: "Unable to inject stable callback helper into react-native-paper Modal commonjs build.",
      },
      {
        from: "const onDismissCallback = (0, _useLatestCallback.default)(onDismiss);",
        to: "const onDismissCallback = useStableLatestCallback(onDismiss);",
        error: "Unable to patch react-native-paper Modal commonjs callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/Snackbar.js",
    replacements: [
      {
        from: "const DURATION_SHORT = 4000;\n",
        to: `function useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}
const DURATION_SHORT = 4000;\n`,
        error: "Unable to inject stable callback helper into react-native-paper Snackbar commonjs build.",
      },
      {
        from: "const animateShow = (0, _useLatestCallback.default)(() => {",
        to: "const animateShow = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar commonjs animateShow callback usage.",
      },
      {
        from: "const handleOnVisible = (0, _useLatestCallback.default)(() => {",
        to: "const handleOnVisible = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar commonjs handleOnVisible callback usage.",
      },
      {
        from: "const handleOnHidden = (0, _useLatestCallback.default)(() => {",
        to: "const handleOnHidden = useStableLatestCallback(() => {",
        error: "Unable to patch react-native-paper Snackbar commonjs handleOnHidden callback usage.",
      },
    ],
  },
  {
    file: "lib/module/components/Card/Card.js",
    replacements: [
      {
        from: "import Surface from '../Surface';\n",
        to: `import Surface from '../Surface';\nconst useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};\n`,
        error: "Unable to inject stable callback helper into react-native-paper Card module build.",
      },
      {
        from: "const handlePressIn = useLatestCallback(e => {",
        to: "const handlePressIn = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Card module handlePressIn callback usage.",
      },
      {
        from: "const handlePressOut = useLatestCallback(e => {",
        to: "const handlePressOut = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Card module handlePressOut callback usage.",
      },
    ],
  },
  {
    file: "lib/module/components/Chip/Chip.js",
    replacements: [
      {
        from: "import Text from '../Typography/Text';\n",
        to: `import Text from '../Typography/Text';\nconst useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};\n`,
        error: "Unable to inject stable callback helper into react-native-paper Chip module build.",
      },
      {
        from: "const handlePressIn = useLatestCallback(e => {",
        to: "const handlePressIn = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Chip module handlePressIn callback usage.",
      },
      {
        from: "const handlePressOut = useLatestCallback(e => {",
        to: "const handlePressOut = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Chip module handlePressOut callback usage.",
      },
    ],
  },
  {
    file: "lib/module/components/Banner.js",
    replacements: [
      {
        from: "const DEFAULT_MAX_WIDTH = 960;\n",
        to: `const useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};\nconst DEFAULT_MAX_WIDTH = 960;\n`,
        error: "Unable to inject stable callback helper into react-native-paper Banner module build.",
      },
      {
        from: "const showCallback = useLatestCallback(onShowAnimationFinished);",
        to: "const showCallback = useStableLatestCallback(onShowAnimationFinished);",
        error: "Unable to patch react-native-paper Banner module show callback usage.",
      },
      {
        from: "const hideCallback = useLatestCallback(onHideAnimationFinished);",
        to: "const hideCallback = useStableLatestCallback(onHideAnimationFinished);",
        error: "Unable to patch react-native-paper Banner module hide callback usage.",
      },
    ],
  },
  {
    file: "lib/module/components/BottomNavigation/BottomNavigation.js",
    replacements: [
      {
        from: "const FAR_FAR_AWAY = Platform.OS === 'web' ? 0 : 9999;\n",
        to: `const useStableLatestCallback = callback => {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
};\nconst FAR_FAR_AWAY = Platform.OS === 'web' ? 0 : 9999;\n`,
        error: "Unable to inject stable callback helper into react-native-paper BottomNavigation module build.",
      },
      {
        from: "const handleTabPress = useLatestCallback(event => {",
        to: "const handleTabPress = useStableLatestCallback(event => {",
        error: "Unable to patch react-native-paper BottomNavigation module handleTabPress callback usage.",
      },
      {
        from: "const jumpTo = useLatestCallback(key => {",
        to: "const jumpTo = useStableLatestCallback(key => {",
        error: "Unable to patch react-native-paper BottomNavigation module jumpTo callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/Card/Card.js",
    replacements: [
      {
        from: "var _Surface = _interopRequireDefault(require(\"../Surface\"));\n",
        to: `var _Surface = _interopRequireDefault(require("../Surface"));\nfunction useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}\n`,
        error: "Unable to inject stable callback helper into react-native-paper Card commonjs build.",
      },
      {
        from: "const handlePressIn = (0, _useLatestCallback.default)(e => {",
        to: "const handlePressIn = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Card commonjs handlePressIn callback usage.",
      },
      {
        from: "const handlePressOut = (0, _useLatestCallback.default)(e => {",
        to: "const handlePressOut = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Card commonjs handlePressOut callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/Chip/Chip.js",
    replacements: [
      {
        from: "var _Text = _interopRequireDefault(require(\"../Typography/Text\"));\n",
        to: `var _Text = _interopRequireDefault(require("../Typography/Text"));\nfunction useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}\n`,
        error: "Unable to inject stable callback helper into react-native-paper Chip commonjs build.",
      },
      {
        from: "const handlePressIn = (0, _useLatestCallback.default)(e => {",
        to: "const handlePressIn = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Chip commonjs handlePressIn callback usage.",
      },
      {
        from: "const handlePressOut = (0, _useLatestCallback.default)(e => {",
        to: "const handlePressOut = useStableLatestCallback(e => {",
        error: "Unable to patch react-native-paper Chip commonjs handlePressOut callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/Banner.js",
    replacements: [
      {
        from: "var _theming = require(\"../core/theming\");\n",
        to: `var _theming = require("../core/theming");\nfunction useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}\n`,
        error: "Unable to inject stable callback helper into react-native-paper Banner commonjs build.",
      },
      {
        from: "const showCallback = (0, _useLatestCallback.default)(onShowAnimationFinished);",
        to: "const showCallback = useStableLatestCallback(onShowAnimationFinished);",
        error: "Unable to patch react-native-paper Banner commonjs show callback usage.",
      },
      {
        from: "const hideCallback = (0, _useLatestCallback.default)(onHideAnimationFinished);",
        to: "const hideCallback = useStableLatestCallback(onHideAnimationFinished);",
        error: "Unable to patch react-native-paper Banner commonjs hide callback usage.",
      },
    ],
  },
  {
    file: "lib/commonjs/components/BottomNavigation/BottomNavigation.js",
    replacements: [
      {
        from: "var _useAnimatedValueArray = _interopRequireDefault(require(\"../../utils/useAnimatedValueArray\"));\n",
        to: `var _useAnimatedValueArray = _interopRequireDefault(require("../../utils/useAnimatedValueArray"));\nfunction useStableLatestCallback(callback) {
  const callbackRef = React.useRef(callback);
  React.useEffect(() => {
    callbackRef.current = callback;
  });
  return React.useCallback((...args) => callbackRef.current?.(...args), []);
}\n`,
        error: "Unable to inject stable callback helper into react-native-paper BottomNavigation commonjs build.",
      },
      {
        from: "const handleTabPress = (0, _useLatestCallback.default)(event => {",
        to: "const handleTabPress = useStableLatestCallback(event => {",
        error: "Unable to patch react-native-paper BottomNavigation commonjs handleTabPress callback usage.",
      },
      {
        from: "const jumpTo = (0, _useLatestCallback.default)(key => {",
        to: "const jumpTo = useStableLatestCallback(key => {",
        error: "Unable to patch react-native-paper BottomNavigation commonjs jumpTo callback usage.",
      },
    ],
  },
];

for (const definition of reactNativePaperPatches) {
  const targetPath = path.join(reactNativePaperRoot, definition.file);
  let contents = readFileSync(targetPath, "utf8");

  for (const replacement of definition.replacements) {
    contents = replaceOnce(contents, replacement.from, replacement.to, replacement.error);
  }

  writeFileSync(targetPath, contents, "utf8");
}

const snackbarStackPackageJsonPath = path.join(snackbarStackRoot, "package.json");
const snackbarStackPackageJson = JSON.parse(readFileSync(snackbarStackPackageJsonPath, "utf8"));

if (
  snackbarStackPackageJson.main !== "src/index.ts" ||
  snackbarStackPackageJson.module !== "src/index.ts" ||
  snackbarStackPackageJson.types !== "src/index.ts"
) {
  snackbarStackPackageJson.main = "src/index.ts";
  snackbarStackPackageJson.module = "src/index.ts";
  snackbarStackPackageJson.types = "src/index.ts";

  writeFileSync(
    snackbarStackPackageJsonPath,
    `${JSON.stringify(snackbarStackPackageJson, null, 2)}\n`,
    "utf8"
  );
}

const useLatestCallbackPath = path.join(useLatestCallbackRoot, "lib/src/index.js");
const useLatestCallbackContents = readFileSync(useLatestCallbackPath, "utf8");
const useLatestCallbackEsmPath = path.join(useLatestCallbackRoot, "esm.mjs");
const useLatestCallbackEsmContents = readFileSync(useLatestCallbackEsmPath, "utf8");

if (!useLatestCallbackContents.includes("module.exports.default = useLatestCallback;")) {
  if (!useLatestCallbackContents.includes("module.exports = useLatestCallback;")) {
    throw new Error("Unable to apply use-latest-callback compatibility patch. Missing expected export.");
  }

  writeFileSync(
    useLatestCallbackPath,
    useLatestCallbackContents.replace(
      "module.exports = useLatestCallback;",
      "module.exports = useLatestCallback;\nmodule.exports.default = useLatestCallback;"
    ),
    "utf8"
  );
}

if (!useLatestCallbackEsmContents.includes("function useLatestCallback(callback) {")) {
  writeFileSync(
    useLatestCallbackEsmPath,
    `import * as React from 'react';
// eslint-disable-next-line import/extensions
import useIsomorphicLayoutEffect from './lib/src/useIsomorphicLayoutEffect.js';

function useLatestCallback(callback) {
  const ref = React.useRef(callback);
  const latestCallback = React.useRef(function latestCallback(...args) {
    return ref.current.apply(this, args);
  }).current;

  useIsomorphicLayoutEffect(() => {
    ref.current = callback;
  });

  return latestCallback;
}

export default useLatestCallback;
`,
    "utf8"
  );
}

console.log("Patched frontend dependencies for React Native Paper compatibility.");
