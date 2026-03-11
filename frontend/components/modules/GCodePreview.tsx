// Based off of:
//
// https://github.com/remcoder/gcode-preview/blob/develop/examples/react-typescript-demo/src/components/GCodePreview.tsx

import * as GCodePreview from 'gcode-preview';

import {
  forwardRef,
  Ref,
  useEffect,
  useImperativeHandle,
  useRef,
  useState
} from 'react';

import * as THREE from 'three';

import Canvas from 'react-native-canvas';
import { Platform } from 'react-native';

interface GCodePreviewProps {
  topLayerColor?: string;
  lastSegmentColor?: string;
  backgroundColor?: string;
  startLayer?: number;
  endLayer?: number;
  lineWidth?: number;
  buildVolume?: { x: number; y: number; z: number };
  renderExtrusion?: boolean;
  renderTravel?: boolean;
}

interface GCodePreviewHandle {
  getLayerCount: () => number;
  processGCode:  (gcode: string | string[]) => void;
  clear:         () => void;
  resize:        () => void;
}

function GCodePreviewUI(
  props: GCodePreviewProps,
  ref: Ref<GCodePreviewHandle>
): JSX.Element {
  const {
    topLayerColor = '',
    lastSegmentColor = '',
    backgroundColor,
    startLayer,
    endLayer,
    lineWidth,
    buildVolume = { x: 250, y: 220, z: 150 },
    renderExtrusion = true,
    renderTravel = true
  } = props;
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const [preview, setPreview] = useState<GCodePreview.WebGLPreview>();

  const resizePreview = () => {
    preview?.resize();
  };

  const createPreview = () => {
    setPreview(
      GCodePreview.init({
        canvas: canvasRef.current as HTMLCanvasElement,
        backgroundColor,
        startLayer,
        endLayer,
        lineWidth,
        topLayerColor: new THREE.Color(topLayerColor).getHex(),
        lastSegmentColor: new THREE.Color(lastSegmentColor).getHex(),
        buildVolume,
        initialCameraPosition: [0, 400, 450],
        allowDragNDrop: false,
        renderExtrusion,
        renderTravel
      })
    );
  };

  useImperativeHandle(ref, () => ({
    getLayerCount() {
      return preview?.layers.length as number;
    },
    processGCode(gcode) {
      preview?.processGCode(gcode);
    },
    clear() {
      preview?.clear();
      preview?.processGCode('');
    },
    resize() {
      resizePreview();
    }
  }));

  useEffect(() => {
    if (!preview) { return; }

    preview.processGCode('');
  }, [preview]);

  useEffect(() => {
    createPreview();

    window.addEventListener('resize', resizePreview);

    return () => {
      window.removeEventListener('resize', resizePreview);
    };
  }, []);

  useEffect(() => {
    if (!preview) { return; }

    createPreview();
  }, [ renderExtrusion, renderTravel ]);

  if (Platform.OS === "web") {
    return <canvas style={{ width: '100%', height: '100%' }} ref={canvasRef} />
  }

  return <Canvas ref={canvasRef} />;

  // <div className="gcode-preview">
  //   <canvas ref={canvasRef}></canvas>

  //   <div>
  //     <div>topLayerColor: {topLayerColor}</div>
  //     <div>lastSegmentColor: {lastSegmentColor}</div>
  //     <div>startLayer: {startLayer}</div>
  //     <div>endLayer: {endLayer}</div>
  //     <div>lineWidth: {lineWidth}</div>
  //   </div>
  // </div>
}

export default forwardRef(GCodePreviewUI);