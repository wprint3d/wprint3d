import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";

export default function useActivePrinterId() {
  return useQuery({
    queryKey: ["activePrinterId"],
    queryFn: async () => {
      const response = await API.get("/user/printer/selected");

      return response?.data ?? response;
    },
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 30000,
  });
}
