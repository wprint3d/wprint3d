import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

const packageRoot = path.resolve("node_modules/react-native-paper-tabs");

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

console.log("Patched react-native-paper-tabs to support full React Native Paper IconSource values in tab headers.");
