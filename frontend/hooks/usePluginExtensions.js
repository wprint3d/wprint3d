import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";

export default function usePluginExtensions(surface) {
  return useQuery({
    queryKey: ["pluginExtensions", surface],
    queryFn: () => API.get("/plugins/ui", { surface }),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 30000,
  });
}
